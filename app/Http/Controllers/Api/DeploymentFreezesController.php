<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeploymentFreeze;
use App\Models\Environment;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class DeploymentFreezesController extends Controller
{
    #[OA\Get(
        summary: 'List deployment freezes',
        description: 'Active, planned and recently ended deployment freezes of the team.',
        path: '/deployment-freezes',
        operationId: 'list-deployment-freezes',
        security: [['bearerAuth' => []]],
        tags: ['Releases'],
        responses: [
            new OA\Response(response: 200, description: 'Freezes.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/DeploymentFreeze'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
        ]
    )]
    public function index(): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $freezes = DeploymentFreeze::query()
            ->where('team_id', $teamId)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()->subDays(30)))
            ->with(['project', 'environment'])
            ->orderByDesc('starts_at')
            ->limit(100)
            ->get();

        return response()->json($freezes->map(fn (DeploymentFreeze $freeze) => self::serialize($freeze))->values());
    }

    #[OA\Post(
        summary: 'Create deployment freeze',
        description: 'Block deployments of the team, a project or an environment for a period.',
        path: '/deployment-freezes',
        operationId: 'create-deployment-freeze',
        security: [['bearerAuth' => []]],
        tags: ['Releases'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['reason'], properties: [
            new OA\Property(property: 'reason', type: 'string', maxLength: 255),
            new OA\Property(property: 'project_uuid', type: 'string', nullable: true),
            new OA\Property(property: 'environment_uuid', type: 'string', nullable: true, description: 'Requires project_uuid.'),
            new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', nullable: true, description: 'Defaults to now.'),
            new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', nullable: true),
            new OA\Property(property: 'allow_rollbacks', type: 'boolean', default: true),
            new OA\Property(property: 'allow_override', type: 'boolean', default: true),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Freeze created.', content: new OA\JsonContent(ref: '#/components/schemas/DeploymentFreeze')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Admin permissions required.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
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
            'reason' => 'required|string|max:255',
            'project_uuid' => 'nullable|string',
            'environment_uuid' => 'nullable|string',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
            'allow_rollbacks' => 'boolean',
            'allow_override' => 'boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $projectId = null;
        $environmentId = null;
        if (filled($request->input('project_uuid'))) {
            $project = Project::query()->where('team_id', $teamId)->where('uuid', $request->input('project_uuid'))->first();
            if ($project === null) {
                return response()->json(['message' => 'Project not found.'], 404);
            }
            $projectId = $project->id;
            if (filled($request->input('environment_uuid'))) {
                $environment = Environment::query()->where('project_id', $project->id)->where('uuid', $request->input('environment_uuid'))->first();
                if ($environment === null) {
                    return response()->json(['message' => 'Environment not found.'], 404);
                }
                $environmentId = $environment->id;
            }
        } elseif (filled($request->input('environment_uuid'))) {
            return response()->json(['message' => 'Validation failed.', 'errors' => ['project_uuid' => ['The project is required for an environment.']]], 422);
        }

        $startsAt = filled($request->input('starts_at')) ? CarbonImmutable::parse($request->input('starts_at'))->utc() : now();
        $endsAt = filled($request->input('ends_at')) ? CarbonImmutable::parse($request->input('ends_at'))->utc() : null;
        if ($endsAt !== null && $endsAt->lte($startsAt)) {
            return response()->json(['message' => 'Validation failed.', 'errors' => ['ends_at' => ['The end must be after the start.']]], 422);
        }

        $freeze = DeploymentFreeze::create([
            'team_id' => $teamId,
            'project_id' => $projectId,
            'environment_id' => $environmentId,
            'reason' => trim((string) $request->input('reason')),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'allow_rollbacks' => $request->boolean('allow_rollbacks', true),
            'allow_override' => $request->boolean('allow_override', true),
            'created_by_user_id' => $request->user()->id,
        ]);
        auditLog('api.deployment_freeze.created', ['team_id' => $teamId, 'freeze_uuid' => $freeze->uuid, 'scope' => $freeze->scope()]);

        return response()->json(self::serialize($freeze->load(['project', 'environment'])), 201);
    }

    #[OA\Post(
        summary: 'End deployment freeze',
        description: 'End a freeze now.',
        path: '/deployment-freezes/{uuid}/end',
        operationId: 'end-deployment-freeze',
        security: [['bearerAuth' => []]],
        tags: ['Releases'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Freeze ended.', content: new OA\JsonContent(ref: '#/components/schemas/DeploymentFreeze')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Admin permissions required.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function end(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $freeze = DeploymentFreeze::query()->where('team_id', $teamId)->where('uuid', $request->route('uuid'))->first();
        if ($freeze === null) {
            return response()->json(['message' => 'Freeze not found.'], 404);
        }
        if (! $request->user()->isAdminOfTeam((int) $teamId)) {
            return response()->json(['message' => 'You need at least admin permissions in this team.'], 403);
        }
        if ($freeze->ended_at === null) {
            $freeze->update(['ended_at' => now(), 'ended_by_user_id' => $request->user()->id]);
            auditLog('api.deployment_freeze.ended', ['team_id' => $teamId, 'freeze_uuid' => $freeze->uuid]);
        }

        return response()->json(self::serialize($freeze->load(['project', 'environment'])));
    }

    /**
     * @return array<string, mixed>
     */
    public static function serialize(DeploymentFreeze $freeze): array
    {
        return [
            'uuid' => $freeze->uuid,
            'reason' => $freeze->reason,
            'scope' => $freeze->scope(),
            'project_uuid' => $freeze->project?->uuid,
            'environment_uuid' => $freeze->environment?->uuid,
            'starts_at' => $freeze->starts_at->toIso8601String(),
            'ends_at' => $freeze->ends_at?->toIso8601String(),
            'ended_at' => $freeze->ended_at?->toIso8601String(),
            'allow_rollbacks' => $freeze->allow_rollbacks,
            'allow_override' => $freeze->allow_override,
            'is_active' => $freeze->isActive(),
        ];
    }
}
