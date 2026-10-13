<?php

namespace App\Services\Platform;

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ReleaseState;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\BackupRestoreEvidence;
use App\Models\DeploymentFreeze;
use App\Models\Environment;
use App\Models\EventDelivery;
use App\Models\EventSubscription;
use App\Models\Release;
use App\Models\Server;
use App\Services\Backup\BackupFreshness;
use App\Services\Deployment\BuildCapacity;
use App\Services\Distribution\BuildIdentity;
use Illuminate\Support\Collection;

/**
 * One read model of a team for dashboards, agents and other consumers. Contains states,
 * counts and identifiers only.
 */
class PlatformOverview
{
    public function __construct(private readonly int $teamId) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $applicationIds = $this->applicationIds();
        $servers = Server::query()->where('team_id', $this->teamId)->with('settings')->orderBy('name')->get();
        $since = now()->subDay();
        $recent = ApplicationDeploymentQueue::query()->whereIn('application_id', $applicationIds)->where('created_at', '>=', $since);
        $identity = BuildIdentity::current();

        return [
            'generated_at' => now()->toIso8601String(),
            'coolify' => [
                'version' => config('constants.coolify.version'),
                'distribution' => $identity->isCustom() ? $identity->distribution : 'official',
                'build' => $identity->isCustom() ? $identity->buildNumber : null,
            ],
            'servers' => $servers->map(fn (Server $server) => [
                'uuid' => $server->uuid,
                'name' => $server->name,
                'reachable' => (bool) $server->settings?->is_reachable,
                'usable' => (bool) $server->settings?->is_usable,
                'build_capacity' => BuildCapacity::forServer($server)->toArray(),
            ])->values()->all(),
            'deployments' => [
                'in_progress' => ApplicationDeploymentQueue::query()->whereIn('application_id', $applicationIds)->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)->count(),
                'queued' => ApplicationDeploymentQueue::query()->whereIn('application_id', $applicationIds)->where('status', ApplicationDeploymentStatus::QUEUED->value)->count(),
                'last_24h' => (clone $recent)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($total) => (int) $total)->all(),
            ],
            'releases' => [
                'active' => Release::query()->where('team_id', $this->teamId)->active()->with('group')->latest('id')->limit(20)->get()->map(fn (Release $release) => $this->release($release))->values()->all(),
                'recent' => Release::query()->where('team_id', $this->teamId)->whereNotIn('state', ReleaseState::activeValues())->with('group')->latest('id')->limit(10)->get()->map(fn (Release $release) => $this->release($release))->values()->all(),
            ],
            'freezes' => DeploymentFreeze::query()->where('team_id', $this->teamId)->upcomingOrActive()->with(['project', 'environment'])->orderBy('starts_at')->get()->map(fn (DeploymentFreeze $freeze) => [
                'uuid' => $freeze->uuid,
                'reason' => $freeze->reason,
                'scope' => $freeze->scope(),
                'project_uuid' => $freeze->project?->uuid,
                'environment_uuid' => $freeze->environment?->uuid,
                'starts_at' => $freeze->starts_at->toIso8601String(),
                'ends_at' => $freeze->ends_at?->toIso8601String(),
                'is_active' => $freeze->isActive(),
            ])->values()->all(),
            'backups' => $this->backups(),
            'event_webhooks' => [
                'enabled' => EventSubscription::query()->where('team_id', $this->teamId)->where('is_enabled', true)->count(),
                'disabled_after_failures' => EventSubscription::query()->where('team_id', $this->teamId)->where('is_enabled', false)->whereNotNull('disabled_reason')->count(),
            ],
        ];
    }

    /**
     * Deployment and release figures over a window, overall and per server.
     *
     * @return array<string, mixed>
     */
    public function metrics(int $hours): array
    {
        $since = now()->subHours($hours);
        $deployments = ApplicationDeploymentQueue::query()
            ->whereIn('application_id', $this->applicationIds())
            ->where('created_at', '>=', $since)
            ->get(['id', 'server_id', 'server_name', 'status', 'created_at', 'admitted_at', 'started_at', 'finished_at', 'updated_at']);

        $releases = Release::query()->where('team_id', $this->teamId)->where('created_at', '>=', $since)->get(['state', 'started_at', 'finished_at']);

        return [
            'window_hours' => $hours,
            'since' => $since->toIso8601String(),
            'deployments' => $this->deploymentFigures($deployments),
            'deployments_by_server' => $deployments->groupBy('server_name')->map(fn (Collection $group) => $this->deploymentFigures($group))->all(),
            'releases' => [
                'total' => $releases->count(),
                'by_state' => $releases->countBy(fn (Release $release) => $release->state->value)->all(),
                'duration_seconds' => $this->percentiles($releases->filter(fn (Release $release) => $release->started_at && $release->finished_at)->map(fn (Release $release) => $release->started_at->diffInSeconds($release->finished_at, true))),
            ],
            'event_deliveries_failed' => EventDelivery::query()
                ->where('state', EventDelivery::FAILED)
                ->where('updated_at', '>=', $since)
                ->whereHas('subscription', fn ($query) => $query->where('team_id', $this->teamId))
                ->count(),
        ];
    }

    /**
     * @param  Collection<int, ApplicationDeploymentQueue>  $deployments
     * @return array<string, mixed>
     */
    private function deploymentFigures(Collection $deployments): array
    {
        return [
            'total' => $deployments->count(),
            'by_status' => $deployments->countBy('status')->all(),
            'queue_wait_seconds' => $this->percentiles($deployments->filter(fn (ApplicationDeploymentQueue $deployment) => $deployment->admitted_at !== null)->map(fn (ApplicationDeploymentQueue $deployment) => $deployment->queueWaitSeconds())),
            'execution_seconds' => $this->percentiles($deployments->filter(fn (ApplicationDeploymentQueue $deployment) => $deployment->started_at !== null && in_array($deployment->status, [ApplicationDeploymentStatus::FINISHED->value, ApplicationDeploymentStatus::FAILED->value], true))->map(fn (ApplicationDeploymentQueue $deployment) => $deployment->executionSeconds())),
        ];
    }

    /**
     * @param  Collection<int, int|float|null>  $values
     * @return array{count: int, p50: int|null, p95: int|null, max: int|null}
     */
    private function percentiles(Collection $values): array
    {
        $sorted = $values->filter(fn ($value) => $value !== null)->map(fn ($value) => (int) $value)->sort()->values();
        $pick = fn (float $percentile) => $sorted->isEmpty() ? null : $sorted[(int) max(0, ceil($percentile * $sorted->count()) - 1)];

        return ['count' => $sorted->count(), 'p50' => $pick(0.5), 'p95' => $pick(0.95), 'max' => $sorted->last()];
    }

    /**
     * @return array<string, mixed>
     */
    private function release(Release $release): array
    {
        return [
            'uuid' => $release->uuid,
            'release_group_uuid' => $release->group?->uuid,
            'release_group_name' => $release->group?->name,
            'state' => $release->state->value,
            'requested_commit' => $release->requested_commit,
            'attention_reason' => $release->attention_reason,
            'created_at' => $release->created_at?->toIso8601String(),
            'finished_at' => $release->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function backups(): array
    {
        $freshness = app(BackupFreshness::class);
        $databases = collect();
        foreach (Environment::ownedByCurrentTeamAPI($this->teamId)->with('project')->get() as $environment) {
            foreach ($freshness->forEnvironment($environment) as $status) {
                $databases->push([...$status, 'environment_uuid' => $environment->uuid, 'environment_name' => $environment->name, 'project_name' => $environment->project?->name]);
            }
        }

        return [
            'databases' => $databases->count(),
            'without_schedule' => $databases->where('configured', false)->map(fn (array $status) => $this->backupRow($status))->values()->all(),
            'oldest_successful_backups' => $databases->where('configured', true)
                ->sortByDesc(fn (array $status) => $status['age_hours'] ?? PHP_INT_MAX)
                ->take(10)
                ->map(fn (array $status) => $this->backupRow($status))
                ->values()
                ->all(),
            'restore_evidence_count' => BackupRestoreEvidence::query()->where('team_id', $this->teamId)->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private function backupRow(array $status): array
    {
        return [
            'database_uuid' => $status['database_uuid'],
            'database_name' => $status['database_name'],
            'environment_name' => $status['environment_name'],
            'project_name' => $status['project_name'],
            'last_success_at' => $status['last_success_at']?->toIso8601String(),
            'age_hours' => $status['age_hours'],
            'last_status' => $status['last_status'],
            'last_restore_verified_at' => $status['last_restore_verified_at'] ?? null,
        ];
    }

    /**
     * application_deployment_queues.application_id is a string column, so ids are compared as strings.
     *
     * @return Collection<int, string>
     */
    private function applicationIds(): Collection
    {
        return Application::ownedByCurrentTeamAPI($this->teamId)->pluck('id')->map(fn ($id) => (string) $id);
    }
}
