<?php

namespace App\Actions\Release;

use App\Enums\ReleaseMemberState;
use App\Enums\ReleaseState;
use App\Jobs\ProcessReleaseJob;
use App\Models\Application;
use App\Models\Release;
use App\Models\ReleaseGroup;
use App\Models\ReleaseGroupMember;
use App\Models\User;
use App\Services\Release\ReleaseGraph;
use App\Services\Release\ReleaseRefused;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

class CreateRelease
{
    use AsAction;

    /**
     * Checks that may be acknowledged up front, for example by a pipeline that releases a hotfix during a freeze.
     */
    public const OVERRIDABLE_CHECKS = ['deployment_freeze', 'release_window', 'backup_freshness'];

    /**
     * @param  list<string>  $acknowledged
     */
    public function handle(ReleaseGroup $group, string $trigger, ?User $user = null, ?string $commit = null, ?string $notes = null, ?string $idempotencyKey = null, ?Release $source = null, array $acknowledged = [], ?string $acknowledgementReason = null): Release
    {
        if ($user !== null) {
            $decision = Gate::forUser($user)->inspect('release', $group);
            if ($decision->denied()) {
                throw new ReleaseRefused($decision->message() ?: 'You are not allowed to start this release.');
            }
        }

        $idempotencyKey = filled($idempotencyKey) ? Str::limit(trim($idempotencyKey), 200, '') : null;
        if ($idempotencyKey !== null && ($existing = $this->existing($group, $idempotencyKey)) !== null) {
            return $existing;
        }

        $acknowledged = array_values(array_intersect(self::OVERRIDABLE_CHECKS, $acknowledged));
        $acknowledgementReason = filled($acknowledgementReason) ? Str::limit(trim($acknowledgementReason), 500, '') : null;
        if ($acknowledged !== [] && $acknowledgementReason === null) {
            throw new ReleaseRefused('Overriding a check requires a reason.');
        }

        $commit = filled($commit) ? trim($commit) : null;
        if ($commit !== null) {
            try {
                $commit = validateGitRef($commit, 'release commit');
            } catch (Throwable $e) {
                throw new ReleaseRefused($e->getMessage());
            }
        }

        try {
            $release = DB::transaction(fn () => $this->create($group, $trigger, $user, $commit, $notes, $idempotencyKey, $source, $acknowledged, $acknowledgementReason));
        } catch (UniqueConstraintViolationException $e) {
            if ($idempotencyKey !== null && ($existing = $this->existing($group, $idempotencyKey)) !== null) {
                return $existing;
            }
            throw $e;
        }

        auditLog(($trigger === Release::TRIGGER_API ? 'api' : 'ui').'.release.created', [
            'team_id' => $group->team_id,
            'release_group_uuid' => $group->uuid,
            'release_uuid' => $release->uuid,
            'correlation_id' => $release->correlation_id,
            'requested_commit' => $commit,
            'members' => $release->members()->count(),
            'acknowledged_checks' => $acknowledged,
        ]);

        ProcessReleaseJob::dispatch($release->id);

        return $release->refresh();
    }

    private function existing(ReleaseGroup $group, string $idempotencyKey): ?Release
    {
        return Release::query()->where('release_group_id', $group->id)->where('idempotency_key', $idempotencyKey)->first();
    }

    /**
     * @param  list<string>  $acknowledged
     */
    private function create(ReleaseGroup $group, string $trigger, ?User $user, ?string $commit, ?string $notes, ?string $idempotencyKey, ?Release $source, array $acknowledged, ?string $acknowledgementReason): Release
    {
        $group = ReleaseGroup::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();

        if (! $group->is_enabled) {
            throw new ReleaseRefused('This release group is disabled.');
        }
        if ($group->activeRelease() !== null) {
            throw new ReleaseRefused('A release of this group is still running. Wait for it to finish or cancel it.');
        }

        $members = $group->members()->where('is_enabled', true)->get();
        if ($members->isEmpty()) {
            throw new ReleaseRefused('The release group has no enabled resources.');
        }

        $applications = Application::query()
            ->whereIn('id', $members->where('resource_type', Application::class)->pluck('resource_id'))
            ->get()
            ->keyBy('id');

        $errors = (new ReleaseGraph($members->mapWithKeys(fn (ReleaseGroupMember $member) => [
            $member->uuid => ['name' => $applications->get($member->resource_id)?->name ?? 'A removed resource', 'phase' => $member->phase, 'depends_on' => $member->dependencies()],
        ])->all()))->errors();
        if ($errors !== []) {
            throw new ReleaseRefused(implode(' ', $errors));
        }

        $release = Release::create([
            'release_group_id' => $group->id,
            'team_id' => $group->team_id,
            'state' => ReleaseState::CREATED,
            'trigger' => $trigger,
            'idempotency_key' => $idempotencyKey,
            'correlation_id' => (string) Str::uuid(),
            'requested_commit' => $commit,
            'notes' => filled($notes) ? Str::limit(trim($notes), 2000, '') : null,
            'requested_by_user_id' => $user?->id,
            'source_release_id' => $source?->id,
            'acknowledged_checks' => $acknowledged,
            'acknowledgement_reason' => $acknowledgementReason,
            'policy_snapshot' => [
                'failure_policy' => $group->failure_policy->value,
                'max_parallel' => $group->max_parallel,
                'require_healthy' => $group->require_healthy,
                'verification_timeout_seconds' => $group->verification_timeout_seconds,
            ],
        ]);

        foreach ($members as $member) {
            $application = $applications->get($member->resource_id);
            $release->members()->create([
                'release_group_member_id' => $member->id,
                'group_member_uuid' => $member->uuid,
                'resource_type' => $member->resource_type,
                'resource_id' => $member->resource_id,
                'resource_name' => $application?->name ?? 'Removed resource',
                'phase' => $member->phase,
                'is_required' => $member->is_required,
                'force_rebuild' => $member->force_rebuild,
                'depends_on' => $member->dependencies(),
                'requested_commit' => $application !== null && $application->build_pack !== 'dockerimage' ? $commit : null,
                'state' => ReleaseMemberState::PENDING,
            ]);
        }

        return $release;
    }
}
