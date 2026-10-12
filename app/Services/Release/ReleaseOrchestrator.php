<?php

namespace App\Services\Release;

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ReleaseFailurePolicy;
use App\Enums\ReleaseMemberState;
use App\Enums\ReleaseState;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Release;
use App\Models\ReleaseMember;
use App\Services\Deployment\DeploymentArtifacts;
use App\Services\Preflight\PreflightResult;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Advances a release one step at a time. Every step reads the stored state, so a crashed worker,
 * a restart or a duplicate tick continues where the last one stopped. Deployments always go
 * through the normal deployment queue and its per-server limits.
 */
class ReleaseOrchestrator
{
    public const LOCK_SECONDS = 120;

    public function __construct(private readonly ReleasePreflight $preflight) {}

    public static function lockFor(Release $release): Lock
    {
        return Cache::lock("release-orchestrator:{$release->id}", self::LOCK_SECONDS);
    }

    public function tick(Release $release): void
    {
        $lock = self::lockFor($release);
        if (! $lock->get()) {
            return;
        }

        try {
            $release->refresh();
            $this->advance($release);
        } finally {
            $lock->release();
        }
    }

    private function advance(Release $release): void
    {
        $members = $release->members()->get();

        foreach ($members as $member) {
            if ($member->state->isInFlight()) {
                $this->sync($release, $member);
            }
        }

        if ($release->state->isTerminal()) {
            return;
        }

        if (in_array($release->state, [ReleaseState::CREATED, ReleaseState::VALIDATING], true) && ! $this->validate($release)) {
            return;
        }

        if ($release->state !== ReleaseState::RUNNING) {
            return;
        }

        $this->applyFailures($release, $members);
        if ($release->state !== ReleaseState::RUNNING) {
            return;
        }

        $this->dispatchEligible($release, $members);
        $this->finishIfDone($release, $members);
    }

    private function validate(Release $release): bool
    {
        $release->update(['state' => ReleaseState::VALIDATING]);
        $results = $this->preflight->run($release);
        $acknowledged = $release->acknowledgedChecks();
        $blocking = PreflightResult::blocking($results, $acknowledged);

        if ($blocking !== []) {
            $release->update([
                'state' => ReleaseState::ATTENTION_REQUIRED,
                'preflight' => array_map(fn (PreflightResult $result) => $result->toArray(), $results),
                'attention_reason' => 'Checks failed: '.implode(' ', array_map(fn (PreflightResult $result) => $result->summary, $blocking)),
            ]);
            $this->audit('system.release.blocked', $release, ['checks' => array_map(fn (PreflightResult $result) => $result->id, $blocking)], 'warning');

            return false;
        }

        $overridden = array_values(array_map(fn (PreflightResult $result) => $result->id, array_filter($results, fn (PreflightResult $result) => $result->blocks() && in_array($result->id, $acknowledged, true))));
        $release->update([
            'state' => ReleaseState::RUNNING,
            'preflight' => array_map(fn (PreflightResult $result) => $result->toArray(), $results),
            'acknowledged_checks' => $overridden,
            'attention_reason' => null,
            'validated_at' => now(),
            'started_at' => $release->started_at ?? now(),
        ]);
        if ($overridden !== []) {
            $this->audit('release.checks_overridden', $release, ['checks' => $overridden, 'reason' => $release->acknowledgement_reason], 'warning');
        }
        $this->audit('system.release.started', $release);

        return true;
    }

    /**
     * @param  Collection<int, ReleaseMember>  $members
     */
    private function applyFailures(Release $release, Collection $members): void
    {
        $accepted = (array) data_get($release->summary, 'accepted_failures', []);
        $unaccepted = $members->filter(fn (ReleaseMember $member) => $member->is_required
            && in_array($member->state, [ReleaseMemberState::FAILED, ReleaseMemberState::CANCELLED], true)
            && ! in_array($member->uuid, $accepted, true));
        $policy = $release->failurePolicy();

        if ($unaccepted->isNotEmpty() && $policy === ReleaseFailurePolicy::MANUAL) {
            $names = $unaccepted->pluck('resource_name')->implode(', ');
            $release->update([
                'state' => ReleaseState::ATTENTION_REQUIRED,
                'attention_reason' => "{$names} did not succeed. Retry the failed resources, continue without them, or cancel the release.",
            ]);
            $this->audit('system.release.paused', $release, ['members' => $unaccepted->pluck('uuid')->all()], 'warning');

            return;
        }

        $graph = $this->graph($members);
        $byUuid = $members->keyBy('group_member_uuid');
        foreach ($members as $member) {
            if (! $member->state->isUnsuccessful()) {
                continue;
            }
            foreach ($graph->dependentsOf($member->group_member_uuid) as $dependentUuid) {
                $dependent = $byUuid->get($dependentUuid);
                if ($dependent?->state === ReleaseMemberState::PENDING) {
                    $this->finishMember($dependent, ReleaseMemberState::SKIPPED, "Skipped because {$member->resource_name} did not succeed.");
                }
            }
        }

        if ($unaccepted->isNotEmpty() && $policy === ReleaseFailurePolicy::STOP) {
            $names = $unaccepted->pluck('resource_name')->implode(', ');
            foreach ($members as $member) {
                if ($member->state === ReleaseMemberState::PENDING) {
                    $this->finishMember($member, ReleaseMemberState::SKIPPED, "Skipped because the release stopped after {$names} failed.");
                }
            }
        }
    }

    /**
     * @param  Collection<int, ReleaseMember>  $members
     */
    private function dispatchEligible(Release $release, Collection $members): void
    {
        $capacity = $release->maxParallel() - $members->filter(fn (ReleaseMember $member) => $member->state->isInFlight())->count();
        $byUuid = $members->keyBy('group_member_uuid');

        foreach ($members->sortBy([['phase', 'asc'], ['id', 'asc']]) as $member) {
            if ($member->state !== ReleaseMemberState::PENDING) {
                continue;
            }

            $earlierPhaseOpen = $members->contains(fn (ReleaseMember $other) => $other->phase < $member->phase && ! $other->state->isTerminal());
            if ($earlierPhaseOpen) {
                $this->note($member, "Waiting for phase {$this->previousOpenPhase($members, $member)} to finish.");

                continue;
            }

            $waitingFor = collect($member->dependencies())
                ->map(fn (string $uuid) => $byUuid->get($uuid))
                ->filter(fn (?ReleaseMember $dependency) => $dependency !== null && $dependency->state !== ReleaseMemberState::SUCCEEDED);
            if ($waitingFor->isNotEmpty()) {
                $this->note($member, 'Waiting for '.$waitingFor->pluck('resource_name')->implode(', ').'.');

                continue;
            }

            if ($capacity <= 0) {
                $this->note($member, "Waiting for a free slot. This release deploys at most {$release->maxParallel()} resources at the same time.");

                continue;
            }

            $this->dispatch($release, $member);
            if ($member->state->isInFlight()) {
                $capacity--;
            }
        }
    }

    private function dispatch(Release $release, ReleaseMember $member): void
    {
        $application = $member->resource_type === Application::class ? Application::query()->find($member->resource_id) : null;
        if ($application === null) {
            $this->finishMember($member, ReleaseMemberState::FAILED, 'The application no longer exists.');

            return;
        }

        $deploymentUuid = $member->deployment_uuid;
        if ($deploymentUuid !== null && ApplicationDeploymentQueue::query()->where('deployment_uuid', $deploymentUuid)->exists()) {
            $member->update(['state' => ReleaseMemberState::QUEUED]);
            $this->sync($release, $member);

            return;
        }

        if ($deploymentUuid === null) {
            // Stored before queueing, so a crash between both steps is retried with the same id.
            $deploymentUuid = new_public_id();
            $member->update([
                'state' => ReleaseMemberState::DISPATCHING,
                'deployment_uuid' => $deploymentUuid,
                'attempts' => $member->attempts + 1,
                'queued_at' => now(),
                'note' => null,
            ]);
        }

        try {
            $result = queue_application_deployment(
                application: $application,
                deployment_uuid: $deploymentUuid,
                commit: $member->requested_commit,
                force_rebuild: $member->force_rebuild,
                freeze_override_reason: in_array('deployment_freeze', $release->acknowledgedChecks(), true) ? $release->acknowledgement_reason : null,
            );
        } catch (Throwable $e) {
            Log::warning("Release {$release->uuid} could not queue {$member->resource_name}: {$e->getMessage()}");
            $this->finishMember($member, ReleaseMemberState::FAILED, 'The deployment could not be queued: '.$e->getMessage());

            return;
        }

        $status = $result['status'] ?? null;
        if ($status === 'queue_full') {
            $member->update([
                'state' => ReleaseMemberState::PENDING,
                'deployment_uuid' => null,
                'attempts' => max(0, $member->attempts - 1),
                'note' => 'The deployment queue of the server is full. The release retries automatically.',
            ]);

            return;
        }

        if ($status === 'skipped' && filled($result['deployment_uuid'] ?? null)) {
            $member->update([
                'state' => ReleaseMemberState::QUEUED,
                'deployment_uuid' => $result['deployment_uuid'],
                'note' => 'Joined a deployment of the same commit that was already queued.',
            ]);
            $this->sync($release, $member);

            return;
        }

        if ($status !== 'queued') {
            $this->finishMember($member, ReleaseMemberState::FAILED, (string) ($result['message'] ?? 'The deployment could not be queued.'));

            return;
        }

        $member->update(['state' => ReleaseMemberState::QUEUED]);
        $this->sync($release, $member);
    }

    private function sync(Release $release, ReleaseMember $member): void
    {
        if ($member->deployment_uuid === null) {
            $member->update(['state' => ReleaseMemberState::PENDING]);

            return;
        }

        $deployment = ApplicationDeploymentQueue::query()->where('deployment_uuid', $member->deployment_uuid)->first();
        if ($deployment === null) {
            if ($member->state !== ReleaseMemberState::DISPATCHING) {
                $this->finishMember($member, ReleaseMemberState::FAILED, 'The deployment record was removed.');
            } elseif ($release->state->isTerminal() || $release->state === ReleaseState::ATTENTION_REQUIRED) {
                $this->finishMember($member, ReleaseMemberState::CANCELLED, 'The release stopped before this resource was queued.');
            } else {
                $this->dispatch($release, $member);
            }

            return;
        }

        $member->fill([
            'deployment_status' => $deployment->status,
            'actual_commit' => $deployment->commit,
            'image_digest' => DeploymentArtifacts::primaryDigest($deployment->artifacts) ?? $member->image_digest,
        ]);

        match ($deployment->status) {
            ApplicationDeploymentStatus::QUEUED->value => $member->fill(['state' => ReleaseMemberState::QUEUED]),
            ApplicationDeploymentStatus::IN_PROGRESS->value => $member->fill([
                'state' => ReleaseMemberState::DEPLOYING,
                'started_at' => $member->started_at ?? $deployment->started_at ?? now(),
                'note' => null,
            ]),
            ApplicationDeploymentStatus::FINISHED->value => $member->fill([
                'state' => ReleaseMemberState::VERIFYING,
                'started_at' => $member->started_at ?? $deployment->started_at ?? $deployment->created_at,
                'deployed_at' => $member->deployed_at ?? $deployment->finished_at ?? now(),
                'note' => null,
            ]),
            default => null,
        };
        $member->save();

        match ($deployment->status) {
            ApplicationDeploymentStatus::FINISHED->value => $this->verify($release, $member, $deployment),
            ApplicationDeploymentStatus::FAILED->value => $this->finishMember($member, ReleaseMemberState::FAILED, 'The deployment failed. Open the deployment log for details.'),
            ApplicationDeploymentStatus::CANCELLED_BY_USER->value => $this->finishMember($member, ReleaseMemberState::CANCELLED, 'The deployment was cancelled.'),
            default => null,
        };
    }

    private function verify(Release $release, ReleaseMember $member, ApplicationDeploymentQueue $deployment): void
    {
        $application = Application::query()->find($member->resource_id);
        $timeout = $release->verificationTimeoutSeconds();
        $deadline = ($member->deployed_at ?? now())->copy()->addSeconds($timeout);

        $children = ApplicationDeploymentQueue::query()
            ->where('parent_deployment_uuid', $deployment->deployment_uuid)
            ->get(['deployment_uuid', 'status', 'server_name']);
        $expectedChildren = $deployment->only_this_server ? 0 : (int) ($application?->additional_servers()->count() ?? 0);
        $unsuccessfulChild = $children->first(fn (ApplicationDeploymentQueue $child) => in_array($child->status, [ApplicationDeploymentStatus::FAILED->value, ApplicationDeploymentStatus::CANCELLED_BY_USER->value], true));
        $finishedChildren = $children->where('status', ApplicationDeploymentStatus::FINISHED->value)->count();
        $containerStatus = (string) ($application?->status ?? 'unknown');
        $healthy = ! $release->requiresHealthy() || (str_starts_with($containerStatus, 'running') && ! str_contains($containerStatus, 'unhealthy'));

        $member->verification = [
            'container_status' => $containerStatus,
            'health_check_enabled' => (bool) ($application?->health_check_enabled ?? false),
            'requires_healthy' => $release->requiresHealthy(),
            'additional_servers_expected' => $expectedChildren,
            'additional_servers_finished' => $finishedChildren,
            'checked_at' => now()->toIso8601String(),
        ];

        if ($unsuccessfulChild !== null) {
            $this->finishMember($member, ReleaseMemberState::FAILED, "The deployment on {$unsuccessfulChild->server_name} did not succeed.");

            return;
        }

        if ($finishedChildren >= $expectedChildren && $healthy) {
            $this->finishMember($member, ReleaseMemberState::SUCCEEDED);

            return;
        }

        if (now()->greaterThan($deadline)) {
            $reason = $finishedChildren < $expectedChildren
                ? "Not every additional server finished within {$timeout} seconds."
                : "The application did not report a running and healthy state within {$timeout} seconds (last status: {$containerStatus}).";
            $this->finishMember($member, ReleaseMemberState::FAILED, $reason);

            return;
        }

        $member->save();
    }

    /**
     * @param  Collection<int, ReleaseMember>  $members
     */
    private function finishIfDone(Release $release, Collection $members): void
    {
        if ($members->contains(fn (ReleaseMember $member) => ! $member->state->isTerminal())) {
            return;
        }

        $requiredUnsuccessful = $members->filter(fn (ReleaseMember $member) => $member->is_required && $member->state->isUnsuccessful());
        $succeeded = $members->where('state', ReleaseMemberState::SUCCEEDED)->count();
        $state = match (true) {
            $requiredUnsuccessful->isEmpty() => ReleaseState::COMPLETED,
            $succeeded > 0 => ReleaseState::PARTIALLY_FAILED,
            default => ReleaseState::FAILED,
        };
        $unsuccessful = $members->filter(fn (ReleaseMember $member) => $member->state->isUnsuccessful());

        $release->update([
            'state' => $state,
            'summary' => array_merge((array) $release->summary, self::counts($members)),
            'failure_summary' => $unsuccessful->isEmpty() ? null : $unsuccessful->map(fn (ReleaseMember $member) => "{$member->resource_name}: {$member->failure_reason}")->implode("\n"),
            'finished_at' => now(),
        ]);
        $this->audit('system.release.finished', $release, ['state' => $state->value], $state === ReleaseState::COMPLETED ? 'info' : 'warning');
    }

    /**
     * @param  Collection<int, ReleaseMember>  $members
     * @return array<string, int>
     */
    public static function counts(Collection $members): array
    {
        $counts = ['total' => $members->count()];
        foreach (ReleaseMemberState::cases() as $state) {
            $counts[$state->value] = $members->filter(fn (ReleaseMember $member) => $member->state === $state)->count();
        }

        return $counts;
    }

    /**
     * @param  Collection<int, ReleaseMember>  $members
     */
    private function graph(Collection $members): ReleaseGraph
    {
        return new ReleaseGraph($members->mapWithKeys(fn (ReleaseMember $member) => [
            $member->group_member_uuid => ['name' => $member->resource_name, 'phase' => $member->phase, 'depends_on' => $member->dependencies()],
        ])->all());
    }

    /**
     * @param  Collection<int, ReleaseMember>  $members
     */
    private function previousOpenPhase(Collection $members, ReleaseMember $member): int
    {
        return (int) $members
            ->filter(fn (ReleaseMember $other) => $other->phase < $member->phase && ! $other->state->isTerminal())
            ->min('phase');
    }

    private function note(ReleaseMember $member, string $note): void
    {
        if ($member->note !== $note) {
            $member->update(['note' => $note]);
        }
    }

    private function finishMember(ReleaseMember $member, ReleaseMemberState $state, ?string $reason = null): void
    {
        $member->fill([
            'state' => $state,
            'failure_reason' => $state === ReleaseMemberState::SUCCEEDED ? null : $reason,
            'note' => null,
            'finished_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function audit(string $event, Release $release, array $context = [], string $level = 'info'): void
    {
        auditLog($event, array_merge([
            'team_id' => $release->team_id,
            'release_uuid' => $release->uuid,
            'release_group_id' => $release->release_group_id,
            'correlation_id' => $release->correlation_id,
        ], $context), $level);
    }
}
