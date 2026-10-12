<?php

namespace App\Http\Controllers\Api;

use App\Actions\Release\CancelRelease;
use App\Actions\Release\CreateRelease;
use App\Actions\Release\ResumeRelease;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Release;
use App\Models\ReleaseGroup;
use App\Models\ReleaseGroupMember;
use App\Models\ReleaseMember;
use App\Models\Server;
use App\Services\Deployment\BuildCapacity;
use App\Services\Release\ReleaseRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class ReleasesController extends Controller
{
    #[OA\Get(
        summary: 'List release groups',
        description: 'List the release groups of the team with their resources and latest release.',
        path: '/release-groups',
        operationId: 'list-release-groups',
        security: [['bearerAuth' => []]],
        tags: ['Releases'],
        responses: [
            new OA\Response(response: 200, description: 'Release groups.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ReleaseGroup'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
        ]
    )]
    public function groups(): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $groups = ReleaseGroup::ownedByTeam((int) $teamId)
            ->with(['environment.project', 'members', 'releases' => fn ($query) => $query->limit(1)])
            ->orderBy('name')
            ->get();
        $applications = $this->applicationsFor($groups->flatMap->members);

        return response()->json($groups->map(fn (ReleaseGroup $group) => $this->group($group, $applications))->values());
    }

    #[OA\Get(
        summary: 'Get release group',
        description: 'Get a release group by UUID.',
        path: '/release-groups/{uuid}',
        operationId: 'get-release-group-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Releases'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Release group.', content: new OA\JsonContent(ref: '#/components/schemas/ReleaseGroup')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function group_by_uuid(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $group = $this->findGroup((int) $teamId, (string) $request->route('uuid'));
        if ($group === null) {
            return response()->json(['message' => 'Release group not found.'], 404);
        }
        $group->load(['environment.project', 'members', 'releases' => fn ($query) => $query->limit(1)]);

        return response()->json($this->group($group, $this->applicationsFor($group->members)));
    }

    #[OA\Get(
        summary: 'List releases of a group',
        description: 'The latest releases of a release group, newest first.',
        path: '/release-groups/{uuid}/releases',
        operationId: 'list-releases-by-group-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Releases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Releases.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Release'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function releases(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $group = $this->findGroup((int) $teamId, (string) $request->route('uuid'));
        if ($group === null) {
            return response()->json(['message' => 'Release group not found.'], 404);
        }
        $limit = max(1, min(100, (int) $request->query('limit', 20)));
        $releases = $group->releases()->with(['members', 'group'])->limit($limit)->get();
        $applications = $this->applicationsFor($releases->flatMap->members);

        return response()->json($releases->map(fn (Release $release) => $this->release($release, $applications))->values());
    }

    #[OA\Get(
        summary: 'Get release',
        description: 'Get a release with the state of every resource in it.',
        path: '/releases/{uuid}',
        operationId: 'get-release-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Releases'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Release.', content: new OA\JsonContent(ref: '#/components/schemas/Release')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function release_by_uuid(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $release = $this->findRelease((int) $teamId, (string) $request->route('uuid'));
        if ($release === null) {
            return response()->json(['message' => 'Release not found.'], 404);
        }

        return response()->json($this->release($release, $this->applicationsFor($release->members)));
    }

    #[OA\Post(
        summary: 'Start release',
        description: 'Start a release of a release group. Send an Idempotency-Key header to make retries safe: a second request with the same key returns the release created by the first one.',
        path: '/release-groups/{uuid}/releases',
        operationId: 'start-release',
        security: [['bearerAuth' => []]],
        tags: ['Releases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string', maxLength: 200)),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object', properties: [
            new OA\Property(property: 'commit', type: 'string', description: 'Commit SHA, branch or tag for every application that builds from Git.'),
            new OA\Property(property: 'notes', type: 'string', maxLength: 2000),
            new OA\Property(property: 'acknowledged_checks', type: 'array', items: new OA\Items(type: 'string', enum: ['deployment_freeze', 'release_window', 'backup_freshness', 'configuration']), description: 'Checks to override if they fail. Requires acknowledgement_reason; every override is audited.'),
            new OA\Property(property: 'acknowledgement_reason', type: 'string', maxLength: 500),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Release created.', content: new OA\JsonContent(ref: '#/components/schemas/Release')),
            new OA\Response(response: 200, description: 'A release with this Idempotency-Key already exists.', content: new OA\JsonContent(ref: '#/components/schemas/Release')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 409, description: 'The release cannot start, for example because another release of the group is running.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function start(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $validator = Validator::make($request->all(), [
            'commit' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'acknowledged_checks' => 'nullable|array',
            'acknowledged_checks.*' => 'string|in:'.implode(',', CreateRelease::OVERRIDABLE_CHECKS),
            'acknowledgement_reason' => 'nullable|string|max:500|required_with:acknowledged_checks',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }
        $group = $this->findGroup((int) $teamId, (string) $request->route('uuid'));
        if ($group === null) {
            return response()->json(['message' => 'Release group not found.'], 404);
        }

        $key = $request->header('Idempotency-Key');
        $existed = filled($key) && $group->releases()->where('idempotency_key', trim((string) $key))->exists();
        try {
            $release = CreateRelease::run($group, Release::TRIGGER_API, $request->user(), $request->input('commit'), $request->input('notes'), $key, null, $request->input('acknowledged_checks', []), $request->input('acknowledgement_reason'));
        } catch (ReleaseRefused $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
        $release->load(['members', 'group']);

        return response()->json($this->release($release, $this->applicationsFor($release->members)), $existed ? 200 : 201);
    }

    #[OA\Post(
        summary: 'Cancel release',
        description: 'Cancel a release. Resources that have not started are not deployed and deployments waiting in the queue are cancelled; running deployments finish.',
        path: '/releases/{uuid}/cancel',
        operationId: 'cancel-release',
        security: [['bearerAuth' => []]],
        tags: ['Releases'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Release cancelled.', content: new OA\JsonContent(ref: '#/components/schemas/Release')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 409, description: 'The release has already ended or is not allowed for this token.'),
        ]
    )]
    public function cancel(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $release = $this->findRelease((int) $teamId, (string) $request->route('uuid'));
        if ($release === null) {
            return response()->json(['message' => 'Release not found.'], 404);
        }
        try {
            $release = CancelRelease::run($release, $request->user(), 'api');
        } catch (ReleaseRefused $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
        $release->load(['members', 'group']);

        return response()->json($this->release($release, $this->applicationsFor($release->members)));
    }

    #[OA\Post(
        summary: 'Continue release',
        description: 'Continue a paused release: retry the failed resources, continue without them, or override failed checks before the first deployment (with a reason, audited).',
        path: '/releases/{uuid}/resume',
        operationId: 'resume-release',
        security: [['bearerAuth' => []]],
        tags: ['Releases'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['mode'], properties: [
            new OA\Property(property: 'mode', type: 'string', enum: ['retry', 'continue', 'override']),
            new OA\Property(property: 'acknowledged_checks', type: 'array', items: new OA\Items(type: 'string'), description: 'For override: the failed checks to override.'),
            new OA\Property(property: 'reason', type: 'string', maxLength: 500, description: 'For override: why the checks are overridden.'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Release continues.', content: new OA\JsonContent(ref: '#/components/schemas/Release')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 409, description: 'The release is not paused or is not allowed for this token.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function resume(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $validator = Validator::make($request->all(), [
            'mode' => 'required|in:retry,continue,override',
            'acknowledged_checks' => 'nullable|array|required_if:mode,override',
            'acknowledged_checks.*' => 'string|max:64',
            'reason' => 'nullable|string|max:500|required_if:mode,override',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }
        $release = $this->findRelease((int) $teamId, (string) $request->route('uuid'));
        if ($release === null) {
            return response()->json(['message' => 'Release not found.'], 404);
        }
        try {
            $release = ResumeRelease::run($release, (string) $request->input('mode'), $request->user(), 'api', $request->input('acknowledged_checks', []), $request->input('reason'));
        } catch (ReleaseRefused $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
        $release->load(['members', 'group']);

        return response()->json($this->release($release, $this->applicationsFor($release->members)));
    }

    #[OA\Get(
        summary: 'Build capacity of a server',
        description: 'Deployment slots of a server: the limit, slots running a deployment, slots reserved for a deployment that waits for a worker, and the queue.',
        path: '/servers/{uuid}/build-capacity',
        operationId: 'get-server-build-capacity',
        security: [['bearerAuth' => []]],
        tags: ['Releases'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Build capacity.', content: new OA\JsonContent(type: 'object', properties: [
                new OA\Property(property: 'server_uuid', type: 'string'),
                new OA\Property(property: 'server_name', type: 'string'),
                new OA\Property(property: 'concurrent_builds', type: 'integer'),
                new OA\Property(property: 'running', type: 'integer'),
                new OA\Property(property: 'reserved', type: 'integer'),
                new OA\Property(property: 'free_slots', type: 'integer'),
                new OA\Property(property: 'queued', type: 'integer'),
                new OA\Property(property: 'queue_limit', type: 'integer'),
                new OA\Property(property: 'oldest_queued_seconds', type: 'integer', nullable: true),
            ])),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function build_capacity(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $server = Server::query()->where('team_id', $teamId)->where('uuid', $request->route('uuid'))->first();
        if ($server === null) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        return response()->json(BuildCapacity::forServer($server)->toArray());
    }

    private function findGroup(int $teamId, string $uuid): ?ReleaseGroup
    {
        return ReleaseGroup::ownedByTeam($teamId)->where('uuid', $uuid)->first();
    }

    private function findRelease(int $teamId, string $uuid): ?Release
    {
        return Release::query()->where('team_id', $teamId)->where('uuid', $uuid)->with(['members', 'group'])->first();
    }

    /**
     * @param  iterable<ReleaseGroupMember|ReleaseMember>  $members
     * @return Collection<int, Application>
     */
    private function applicationsFor(iterable $members)
    {
        $ids = collect($members)->where('resource_type', Application::class)->pluck('resource_id')->unique()->values();

        return Application::query()->whereIn('id', $ids)->get(['id', 'uuid', 'name'])->keyBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function group(ReleaseGroup $group, $applications): array
    {
        $latest = $group->releases->first();

        return [
            'uuid' => $group->uuid,
            'name' => $group->name,
            'description' => $group->description,
            'project_uuid' => $group->environment?->project?->uuid,
            'environment_uuid' => $group->environment?->uuid,
            'environment_name' => $group->environment?->name,
            'is_enabled' => $group->is_enabled,
            'failure_policy' => $group->failure_policy->value,
            'max_parallel' => $group->max_parallel,
            'require_healthy' => $group->require_healthy,
            'verification_timeout_seconds' => $group->verification_timeout_seconds,
            'members' => $group->members->map(fn (ReleaseGroupMember $member) => [
                'uuid' => $member->uuid,
                'resource_type' => 'application',
                'resource_uuid' => $applications->get($member->resource_id)?->uuid,
                'resource_name' => $applications->get($member->resource_id)?->name,
                'phase' => $member->phase,
                'is_required' => $member->is_required,
                'is_enabled' => $member->is_enabled,
                'force_rebuild' => $member->force_rebuild,
                'depends_on' => $member->dependencies(),
            ])->values(),
            'latest_release' => $latest ? [
                'uuid' => $latest->uuid,
                'state' => $latest->state->value,
                'created_at' => $latest->created_at?->toIso8601String(),
                'finished_at' => $latest->finished_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function release(Release $release, $applications): array
    {
        return [
            'uuid' => $release->uuid,
            'release_group_uuid' => $release->group?->uuid,
            'state' => $release->state->value,
            'trigger' => $release->trigger,
            'correlation_id' => $release->correlation_id,
            'requested_commit' => $release->requested_commit,
            'notes' => $release->notes,
            'failure_policy' => $release->failurePolicy()->value,
            'failure_summary' => $release->failure_summary,
            'attention_reason' => $release->attention_reason,
            'preflight' => $release->preflight ?? [],
            'acknowledged_checks' => $release->acknowledgedChecks(),
            'acknowledgement_reason' => $release->acknowledgement_reason,
            'summary' => $release->summary,
            'created_at' => $release->created_at?->toIso8601String(),
            'started_at' => $release->started_at?->toIso8601String(),
            'finished_at' => $release->finished_at?->toIso8601String(),
            'cancelled_at' => $release->cancelled_at?->toIso8601String(),
            'members' => $release->members->map(fn (ReleaseMember $member) => [
                'uuid' => $member->uuid,
                'resource_type' => 'application',
                'resource_uuid' => $applications->get($member->resource_id)?->uuid,
                'resource_name' => $member->resource_name,
                'phase' => $member->phase,
                'is_required' => $member->is_required,
                'depends_on' => $member->dependencies(),
                'state' => $member->state->value,
                'deployment_uuid' => $member->deployment_uuid,
                'deployment_status' => $member->deployment_status,
                'requested_commit' => $member->requested_commit,
                'actual_commit' => $member->actual_commit,
                'image_digest' => $member->image_digest,
                'failure_reason' => $member->failure_reason,
                'note' => $member->note,
                'attempts' => $member->attempts,
                'verification' => $member->verification,
                'started_at' => $member->started_at?->toIso8601String(),
                'finished_at' => $member->finished_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
