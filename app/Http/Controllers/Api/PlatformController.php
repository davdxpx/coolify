<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\BackupRestoreEvidence;
use App\Models\Environment;
use App\Services\Backup\BackupFreshness;
use App\Services\Platform\PlatformOverview;
use App\Services\Platform\RollbackAdvisor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class PlatformController extends Controller
{
    #[OA\Get(
        summary: 'Platform overview',
        description: 'One read model of the team: Coolify version, servers with build capacity, deployment counts, active and recent releases, deployment freezes, database backup freshness and event webhook health. Identifiers and states only.',
        path: '/platform/overview',
        operationId: 'get-platform-overview',
        security: [['bearerAuth' => []]],
        tags: ['Platform'],
        responses: [
            new OA\Response(response: 200, description: 'Overview.', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
        ]
    )]
    public function overview(): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        return response()->json((new PlatformOverview((int) $teamId))->overview());
    }

    #[OA\Get(
        summary: 'Platform metrics',
        description: 'Deployment counts by status, queue wait and execution time percentiles (overall and per server), release outcomes and failed event deliveries over a window.',
        path: '/platform/metrics',
        operationId: 'get-platform-metrics',
        security: [['bearerAuth' => []]],
        tags: ['Platform'],
        parameters: [new OA\Parameter(name: 'hours', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 24, minimum: 1, maximum: 720))],
        responses: [
            new OA\Response(response: 200, description: 'Metrics.', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
        ]
    )]
    public function metrics(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $hours = max(1, min(720, (int) $request->query('hours', 24)));

        return response()->json((new PlatformOverview((int) $teamId))->metrics($hours));
    }

    #[OA\Get(
        summary: 'Rollback candidates',
        description: 'Earlier successful deployments of an application with their images, the configuration and shared variable changes made since, and warnings. With check_images=true the server is asked which images still exist.',
        path: '/applications/{uuid}/rollback-candidates',
        operationId: 'get-application-rollback-candidates',
        security: [['bearerAuth' => []]],
        tags: ['Platform'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10, maximum: 25)),
            new OA\Parameter(name: 'check_images', in: 'query', required: false, schema: new OA\Schema(type: 'boolean', default: false)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Candidates.', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function rollbackCandidates(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $application = Application::ownedByCurrentTeamAPI((int) $teamId)->where('uuid', $request->route('uuid'))->first();
        if ($application === null) {
            return response()->json(['message' => 'Application not found.'], 404);
        }
        $advisor = app(RollbackAdvisor::class);
        $tags = $request->boolean('check_images') ? $advisor->localImageTags($application) : null;

        return response()->json($advisor->candidates($application, (int) $request->query('limit', 10), $tags));
    }

    #[OA\Get(
        summary: 'Backup freshness',
        description: 'Per database of the team (or of one environment): whether a backup schedule is enabled, the last successful backup, its age and the last recorded restore test.',
        path: '/backups/freshness',
        operationId: 'get-backup-freshness',
        security: [['bearerAuth' => []]],
        tags: ['Platform'],
        parameters: [new OA\Parameter(name: 'environment_uuid', in: 'query', required: false, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Freshness per database.', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function backupFreshness(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $environments = Environment::ownedByCurrentTeamAPI((int) $teamId)->with('project');
        if (filled($request->query('environment_uuid'))) {
            $environments->where('uuid', $request->query('environment_uuid'));
        }
        $environments = $environments->get();
        if ($environments->isEmpty() && filled($request->query('environment_uuid'))) {
            return response()->json(['message' => 'Environment not found.'], 404);
        }

        $freshness = app(BackupFreshness::class);
        $rows = [];
        foreach ($environments as $environment) {
            foreach ($freshness->forEnvironment($environment) as $status) {
                $rows[] = [
                    ...$status,
                    'last_success_at' => $status['last_success_at']?->toIso8601String(),
                    'last_attempt_at' => $status['last_attempt_at']?->toIso8601String(),
                    'environment_uuid' => $environment->uuid,
                    'project_uuid' => $environment->project?->uuid,
                ];
            }
        }

        return response()->json($rows);
    }

    #[OA\Get(
        summary: 'Restore tests of a database',
        path: '/databases/{uuid}/restore-evidence',
        operationId: 'list-database-restore-evidence',
        security: [['bearerAuth' => []]],
        tags: ['Platform'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Recorded restore tests, newest first.', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function restoreEvidence(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $database = $this->database((int) $teamId, (string) $request->route('uuid'));
        if ($database === null) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        return response()->json(BackupRestoreEvidence::query()
            ->where('database_type', $database->getMorphClass())
            ->where('database_id', $database->getKey())
            ->latest('verified_at')
            ->limit(50)
            ->get()
            ->map->toApiArray()
            ->values());
    }

    #[OA\Post(
        summary: 'Record a restore test',
        description: 'Record that a backup of the database was restored and checked, for example in a rehearsal on a separate host. Coolify stores the evidence; it does not perform the restore.',
        path: '/databases/{uuid}/restore-evidence',
        operationId: 'create-database-restore-evidence',
        security: [['bearerAuth' => []]],
        tags: ['Platform'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['result'], properties: [
            new OA\Property(property: 'result', type: 'string', enum: ['passed', 'failed']),
            new OA\Property(property: 'method', type: 'string', enum: ['manual', 'automated'], default: 'manual'),
            new OA\Property(property: 'backup_execution_uuid', type: 'string', nullable: true),
            new OA\Property(property: 'verified_at', type: 'string', format: 'date-time', nullable: true),
            new OA\Property(property: 'notes', type: 'string', maxLength: 2000, nullable: true),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Recorded.', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Admin permissions required.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function storeRestoreEvidence(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $database = $this->database((int) $teamId, (string) $request->route('uuid'));
        if ($database === null) {
            return response()->json(['message' => 'Database not found.'], 404);
        }
        if (! $request->user()->isAdminOfTeam((int) $teamId)) {
            return response()->json(['message' => 'You need at least admin permissions in this team.'], 403);
        }
        $validator = Validator::make($request->all(), [
            'result' => 'required|in:'.implode(',', BackupRestoreEvidence::RESULTS),
            'method' => 'nullable|in:'.implode(',', BackupRestoreEvidence::METHODS),
            'backup_execution_uuid' => 'nullable|string|max:255',
            'verified_at' => 'nullable|date|before_or_equal:now',
            'notes' => 'nullable|string|max:2000',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $evidence = BackupRestoreEvidence::create([
            'team_id' => $teamId,
            'database_type' => $database->getMorphClass(),
            'database_id' => $database->getKey(),
            'backup_execution_uuid' => $request->input('backup_execution_uuid'),
            'result' => $request->input('result'),
            'method' => $request->input('method', 'manual'),
            'notes' => $request->input('notes'),
            'verified_at' => filled($request->input('verified_at')) ? CarbonImmutable::parse($request->input('verified_at')) : now(),
            'recorded_by_user_id' => $request->user()->id,
        ]);
        auditLog('api.backup.restore_evidence_recorded', ['team_id' => $teamId, 'database_uuid' => $database->uuid, 'result' => $evidence->result]);

        return response()->json($evidence->toApiArray(), 201);
    }

    private function database(int $teamId, string $uuid): ?Model
    {
        $resource = queryDatabaseByUuidWithinTeam($uuid, (string) $teamId);

        return $resource instanceof Model ? $resource : null;
    }
}
