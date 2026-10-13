<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Services\Deployment\ComposeBlueGreen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class ZeroDowntimeController extends Controller
{
    #[OA\Get(
        summary: 'Zero-downtime deployment of a Compose application',
        description: 'Whether zero-downtime (blue/green) deployment is enabled and whether the Compose file allows it, with the reasons.',
        path: '/applications/{uuid}/zero-downtime',
        operationId: 'get-application-zero-downtime',
        security: [['bearerAuth' => []]],
        tags: ['Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'State and eligibility.', content: new OA\JsonContent(type: 'object', properties: [
                new OA\Property(property: 'enabled', type: 'boolean'),
                new OA\Property(property: 'eligible', type: 'boolean'),
                new OA\Property(property: 'blocking', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'warnings', type: 'array', items: new OA\Items(type: 'string')),
            ])),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $application = Application::ownedByCurrentTeamAPI((int) $teamId)->where('uuid', $request->route('uuid'))->first();
        if ($application === null) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        return response()->json($this->state($application));
    }

    #[OA\Patch(
        summary: 'Enable or disable zero-downtime deployment',
        path: '/applications/{uuid}/zero-downtime',
        operationId: 'update-application-zero-downtime',
        security: [['bearerAuth' => []]],
        tags: ['Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['enabled'], properties: [
            new OA\Property(property: 'enabled', type: 'boolean'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Updated state.', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not allowed to update the application.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, description: 'The Compose file does not allow it; `blocking` lists why.'),
        ]
    )]
    public function update(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $application = Application::ownedByCurrentTeamAPI((int) $teamId)->where('uuid', $request->route('uuid'))->first();
        if ($application === null) {
            return response()->json(['message' => 'Application not found.'], 404);
        }
        if ($request->user()->cannot('update', $application)) {
            return response()->json(['message' => 'You are not allowed to update this application.'], 403);
        }
        $validator = Validator::make($request->all(), ['enabled' => 'required|boolean']);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $enabled = $request->boolean('enabled');
        $state = $this->state($application);
        if ($enabled && ! $state['eligible']) {
            return response()->json(['message' => 'This application cannot be deployed without downtime yet.', ...$state], 422);
        }
        $application->settings->update(['is_compose_blue_green_enabled' => $enabled]);
        auditLog('api.application.zero_downtime_changed', ['team_id' => $teamId, 'application_uuid' => $application->uuid, 'enabled' => $enabled]);

        return response()->json($this->state($application->fresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function state(Application $application): array
    {
        $report = app(ComposeBlueGreen::class)->analyze($application);

        return [
            'enabled' => (bool) $application->settings?->is_compose_blue_green_enabled,
            'eligible' => $report['eligible'],
            'blocking' => $report['blocking'],
            'warnings' => $report['warnings'],
        ];
    }
}
