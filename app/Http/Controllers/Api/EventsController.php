<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverEventJob;
use App\Models\EventDelivery;
use App\Models\EventSubscription;
use App\Models\PlatformEvent;
use App\Rules\SafeWebhookUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class EventsController extends Controller
{
    #[OA\Get(
        summary: 'Events feed',
        description: 'Team events in recording order. Poll with `after` set to the last `id` you processed; the response contains `next_after` for the next call. Events are kept for 30 days.',
        path: '/events',
        operationId: 'list-events',
        security: [['bearerAuth' => []]],
        tags: ['Events'],
        parameters: [
            new OA\Parameter(name: 'after', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 0)),
            new OA\Parameter(name: 'types', in: 'query', required: false, description: 'Comma-separated event types.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 100, maximum: 500)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Events.', content: new OA\JsonContent(type: 'object', properties: [
                new OA\Property(property: 'events', type: 'array', items: new OA\Items(ref: '#/components/schemas/PlatformEvent')),
                new OA\Property(property: 'next_after', type: 'integer'),
            ])),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $after = max(0, (int) $request->query('after', 0));
        $limit = max(1, min(500, (int) $request->query('limit', 100)));
        $types = array_values(array_filter(array_map('trim', explode(',', (string) $request->query('types', '')))));

        $events = PlatformEvent::query()
            ->where('team_id', $teamId)
            ->where('id', '>', $after)
            ->when($types !== [], fn ($query) => $query->whereIn('type', $types))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'events' => $events->map(fn (PlatformEvent $event) => $event->toPayload())->values(),
            'next_after' => $events->last()?->id ?? $after,
        ]);
    }

    #[OA\Get(
        summary: 'List event webhooks',
        path: '/event-subscriptions',
        operationId: 'list-event-subscriptions',
        security: [['bearerAuth' => []]],
        tags: ['Events'],
        responses: [
            new OA\Response(response: 200, description: 'Subscriptions. The target URL is shown by host only.', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
        ]
    )]
    public function subscriptions(): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        return response()->json(EventSubscription::query()->where('team_id', $teamId)->orderBy('name')->get()->map->toApiArray()->values());
    }

    #[OA\Post(
        summary: 'Create event webhook',
        description: 'Deliver team events to an HTTPS endpoint. Every request carries `Coolify-Event`, `Coolify-Delivery` and `Coolify-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of "<time>.<body>" with the secret>`. The secret is returned once. Private and internal addresses are refused.',
        path: '/event-subscriptions',
        operationId: 'create-event-subscription',
        security: [['bearerAuth' => []]],
        tags: ['Events'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['name', 'url'], properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'url', type: 'string', format: 'uri'),
            new OA\Property(property: 'event_types', type: 'array', items: new OA\Items(type: 'string'), description: 'Event types, or ["*"] for all (default).'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Subscription created. Contains `secret` this one time.', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Admin permissions required.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if (! $request->user()->isAdminOfTeam((int) $teamId)) {
            return response()->json(['message' => 'You need at least admin permissions in this team.'], 403);
        }
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'url' => ['required', 'string', 'max:2000', 'url:https', new SafeWebhookUrl],
            'event_types' => 'nullable|array|min:1',
            'event_types.*' => 'string|in:*,'.implode(',', PlatformEvent::TYPES),
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }
        if (EventSubscription::query()->where('team_id', $teamId)->count() >= EventSubscription::MAX_PER_TEAM) {
            return response()->json(['message' => 'A team can have at most '.EventSubscription::MAX_PER_TEAM.' event webhooks.'], 422);
        }

        $secret = 'whsec_'.Str::random(40);
        $subscription = EventSubscription::create([
            'team_id' => $teamId,
            'name' => $request->input('name'),
            'url' => $request->input('url'),
            'secret' => $secret,
            'event_types' => $request->input('event_types') ?: ['*'],
            'created_by_user_id' => $request->user()->id,
        ]);
        auditLog('api.event_subscription.created', ['team_id' => $teamId, 'subscription_uuid' => $subscription->uuid, 'url_host' => parse_url((string) $subscription->url, PHP_URL_HOST)]);

        return response()->json([...$subscription->toApiArray(), 'secret' => $secret], 201);
    }

    #[OA\Delete(
        summary: 'Delete event webhook',
        path: '/event-subscriptions/{uuid}',
        operationId: 'delete-event-subscription',
        security: [['bearerAuth' => []]],
        tags: ['Events'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Deleted.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Admin permissions required.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function destroy(Request $request): JsonResponse
    {
        [$subscription, $error] = $this->findForAdmin($request);
        if ($error) {
            return $error;
        }
        $subscription->delete();
        auditLog('api.event_subscription.deleted', ['team_id' => $subscription->team_id, 'subscription_uuid' => $subscription->uuid]);

        return response()->json(['message' => 'Event webhook deleted.']);
    }

    #[OA\Post(
        summary: 'Send a test event',
        description: 'Records a `ping` event for this subscription only and enables a subscription that was disabled after failures.',
        path: '/event-subscriptions/{uuid}/test',
        operationId: 'test-event-subscription',
        security: [['bearerAuth' => []]],
        tags: ['Events'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 202, description: 'Test delivery queued.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Admin permissions required.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function test(Request $request): JsonResponse
    {
        [$subscription, $error] = $this->findForAdmin($request);
        if ($error) {
            return $error;
        }
        $subscription->update(['is_enabled' => true, 'disabled_reason' => null, 'consecutive_failures' => 0]);
        $event = PlatformEvent::create([
            'team_id' => null,
            'type' => 'ping',
            'subject_type' => 'event_subscription',
            'subject_uuid' => $subscription->uuid,
            'data' => ['message' => 'Test event from Coolify.'],
            'occurred_at' => now(),
        ]);
        $delivery = EventDelivery::create(['platform_event_id' => $event->id, 'event_subscription_id' => $subscription->id, 'state' => EventDelivery::PENDING, 'next_attempt_at' => now()]);
        DeliverEventJob::dispatch($delivery->id);

        return response()->json(['message' => 'Test event queued.', 'delivery_uuid' => $delivery->uuid], 202);
    }

    #[OA\Get(
        summary: 'Recent deliveries of an event webhook',
        path: '/event-subscriptions/{uuid}/deliveries',
        operationId: 'list-event-subscription-deliveries',
        security: [['bearerAuth' => []]],
        tags: ['Events'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The last 50 deliveries.', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function deliveries(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $subscription = EventSubscription::query()->where('team_id', $teamId)->where('uuid', $request->route('uuid'))->first();
        if ($subscription === null) {
            return response()->json(['message' => 'Event webhook not found.'], 404);
        }

        return response()->json($subscription->deliveries()->with('event:id,uuid,type')->latest('id')->limit(50)->get()->map(fn (EventDelivery $delivery) => [
            'uuid' => $delivery->uuid,
            'event_uuid' => $delivery->event?->uuid,
            'event_type' => $delivery->event?->type,
            'state' => $delivery->state,
            'attempts' => $delivery->attempts,
            'response_status' => $delivery->response_status,
            'last_error' => $delivery->last_error,
            'next_attempt_at' => $delivery->next_attempt_at?->toIso8601String(),
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
        ])->values());
    }

    /**
     * @return array{0: EventSubscription|null, 1: JsonResponse|null}
     */
    private function findForAdmin(Request $request): array
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return [null, invalidTokenResponse()];
        }
        $subscription = EventSubscription::query()->where('team_id', $teamId)->where('uuid', $request->route('uuid'))->first();
        if ($subscription === null) {
            return [null, response()->json(['message' => 'Event webhook not found.'], 404)];
        }
        if (! $request->user()->isAdminOfTeam((int) $teamId)) {
            return [null, response()->json(['message' => 'You need at least admin permissions in this team.'], 403)];
        }

        return [$subscription, null];
    }
}
