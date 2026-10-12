<?php

namespace App\Actions\Release;

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\DeploymentPhase;
use App\Enums\ReleaseMemberState;
use App\Enums\ReleaseState;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Release;
use App\Models\ReleaseMember;
use App\Models\User;
use App\Services\Release\ReleaseOrchestrator;
use App\Services\Release\ReleaseRefused;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Stops a release: nothing new starts and deployments that are still waiting in the queue are
 * cancelled. Deployments that already run are not interrupted and finish normally.
 */
class CancelRelease
{
    use AsAction;

    public function handle(Release $release, ?User $user = null, string $source = 'ui'): Release
    {
        if ($user !== null) {
            $decision = Gate::forUser($user)->inspect('release', $release->group);
            if ($decision->denied()) {
                throw new ReleaseRefused($decision->message() ?: 'You are not allowed to cancel this release.');
            }
        }

        try {
            return ReleaseOrchestrator::lockFor($release)->block(15, fn () => $this->cancel($release->refresh(), $user, $source));
        } catch (LockTimeoutException) {
            throw new ReleaseRefused('The release is being updated right now. Try again in a few seconds.');
        }
    }

    private function cancel(Release $release, ?User $user, string $source): Release
    {
        if ($release->state->isTerminal()) {
            throw new ReleaseRefused('The release has already ended.');
        }

        foreach ($release->members()->get() as $member) {
            if ($member->state === ReleaseMemberState::PENDING) {
                $this->cancelMember($member, 'The release was cancelled before this resource started.');

                continue;
            }
            if (! in_array($member->state, [ReleaseMemberState::DISPATCHING, ReleaseMemberState::QUEUED], true)) {
                continue;
            }

            $deployment = $member->deployment_uuid ? ApplicationDeploymentQueue::query()->where('deployment_uuid', $member->deployment_uuid)->first() : null;
            if ($deployment === null) {
                $this->cancelMember($member, 'The release was cancelled before this resource was queued.');

                continue;
            }

            $cancelled = ApplicationDeploymentQueue::query()
                ->whereKey($deployment->id)
                ->where('status', ApplicationDeploymentStatus::QUEUED->value)
                ->update(['status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value]);
            if ($cancelled === 1) {
                $deployment->refresh();
                $deployment->recordPhase(DeploymentPhase::CANCELLED);
                $deployment->addLogEntry('Deployment cancelled because its release was cancelled.', 'stderr');
                $member->deployment_status = ApplicationDeploymentStatus::CANCELLED_BY_USER->value;
                $this->cancelMember($member, 'The release was cancelled while this deployment waited in the queue.');
            }
        }

        $release->update([
            'state' => ReleaseState::CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $user?->id,
            'finished_at' => now(),
            'attention_reason' => null,
            'summary' => array_merge((array) $release->summary, ReleaseOrchestrator::counts($release->members()->get())),
        ]);

        auditLog("{$source}.release.cancelled", [
            'team_id' => $release->team_id,
            'release_uuid' => $release->uuid,
            'correlation_id' => $release->correlation_id,
        ], 'warning');

        return $release;
    }

    private function cancelMember(ReleaseMember $member, string $reason): void
    {
        $member->fill([
            'state' => ReleaseMemberState::CANCELLED,
            'failure_reason' => $reason,
            'note' => null,
            'finished_at' => now(),
        ])->save();
    }
}
