<?php

namespace App\Actions\Release;

use App\Enums\ReleaseMemberState;
use App\Enums\ReleaseState;
use App\Jobs\ProcessReleaseJob;
use App\Models\Release;
use App\Models\User;
use App\Services\Preflight\PreflightResult;
use App\Services\Release\ReleaseOrchestrator;
use App\Services\Release\ReleaseRefused;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

class ResumeRelease
{
    use AsAction;

    public const RETRY = 'retry';

    public const CONTINUE = 'continue';

    public const OVERRIDE = 'override';

    /**
     * @param  list<string>  $acknowledged
     */
    public function handle(Release $release, string $mode, ?User $user = null, string $source = 'ui', array $acknowledged = [], ?string $reason = null): Release
    {
        if (! in_array($mode, [self::RETRY, self::CONTINUE, self::OVERRIDE], true)) {
            throw new ReleaseRefused('Unknown way to continue the release.');
        }
        if ($user !== null) {
            $decision = Gate::forUser($user)->inspect('release', $release->group);
            if ($decision->denied()) {
                throw new ReleaseRefused($decision->message() ?: 'You are not allowed to continue this release.');
            }
        }

        try {
            $release = ReleaseOrchestrator::lockFor($release)->block(15, fn () => $this->resume($release->refresh(), $mode, $acknowledged, $reason));
        } catch (LockTimeoutException) {
            throw new ReleaseRefused('The release is being updated right now. Try again in a few seconds.');
        }

        auditLog("{$source}.release.resumed", [
            'team_id' => $release->team_id,
            'release_uuid' => $release->uuid,
            'correlation_id' => $release->correlation_id,
            'mode' => $mode,
            'acknowledged_checks' => $mode === self::OVERRIDE ? $release->acknowledgedChecks() : [],
            'reason' => $mode === self::OVERRIDE ? $release->acknowledgement_reason : null,
        ], $mode === self::OVERRIDE ? 'warning' : 'info');
        ProcessReleaseJob::dispatch($release->id);

        return $release->refresh();
    }

    /**
     * @param  list<string>  $acknowledged
     */
    private function resume(Release $release, string $mode, array $acknowledged, ?string $reason): Release
    {
        if ($release->state !== ReleaseState::ATTENTION_REQUIRED) {
            throw new ReleaseRefused('Only a paused release can be continued.');
        }

        if ($release->validated_at === null) {
            $update = ['state' => ReleaseState::CREATED, 'attention_reason' => null];
            if ($mode === self::OVERRIDE) {
                $overridable = array_map(fn (PreflightResult $result) => $result->id, array_filter($release->preflightResults(), fn (PreflightResult $result) => $result->blocks() && $result->overridable));
                $acknowledged = array_values(array_intersect($overridable, $acknowledged));
                if ($acknowledged === []) {
                    throw new ReleaseRefused('None of the failed checks can be overridden.');
                }
                if (blank($reason)) {
                    throw new ReleaseRefused('Overriding a check requires a reason.');
                }
                $update['acknowledged_checks'] = array_values(array_unique([...$release->acknowledgedChecks(), ...$acknowledged]));
                $update['acknowledgement_reason'] = Str::limit(trim($reason), 500, '');
            }
            $release->update($update);

            return $release;
        }

        if ($mode === self::OVERRIDE) {
            throw new ReleaseRefused('Checks can only be overridden before the release deploys.');
        }

        $accepted = (array) data_get($release->summary, 'accepted_failures', []);
        $failed = $release->members()->get()->filter(fn ($member) => $member->is_required
            && in_array($member->state, [ReleaseMemberState::FAILED, ReleaseMemberState::CANCELLED], true)
            && ! in_array($member->uuid, $accepted, true));

        if ($mode === self::RETRY) {
            foreach ($failed as $member) {
                $member->update([
                    'state' => ReleaseMemberState::PENDING,
                    'deployment_uuid' => null,
                    'deployment_status' => null,
                    'actual_commit' => null,
                    'verification' => null,
                    'failure_reason' => null,
                    'note' => 'Retry requested.',
                    'queued_at' => null,
                    'started_at' => null,
                    'deployed_at' => null,
                    'finished_at' => null,
                ]);
            }
        } else {
            $release->summary = array_merge((array) $release->summary, ['accepted_failures' => array_values(array_unique([...$accepted, ...$failed->pluck('uuid')->all()]))]);
        }

        $release->state = ReleaseState::RUNNING;
        $release->attention_reason = null;
        $release->save();

        return $release;
    }
}
