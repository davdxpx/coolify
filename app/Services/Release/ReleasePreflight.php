<?php

namespace App\Services\Release;

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\PreflightState;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Release;
use App\Models\ReleaseMember;
use App\Services\Preflight\PreflightResult;
use Illuminate\Support\Collection;

class ReleasePreflight
{
    /**
     * @return list<PreflightResult>
     */
    public function run(Release $release): array
    {
        $release->loadMissing('group');
        $members = $release->members()->get();
        $applications = Application::query()
            ->whereIn('id', $members->where('resource_type', Application::class)->pluck('resource_id'))
            ->with(['destination.server.settings', 'environment.project'])
            ->get()
            ->keyBy('id');

        return [
            $this->resources($release, $members, $applications),
            $this->dependencies($members),
            $this->servers($members, $applications),
            $this->overlappingReleases($release, $members),
            $this->deploymentQueues($applications),
        ];
    }

    /**
     * @param  Collection<int, ReleaseMember>  $members
     * @param  Collection<int, Application>  $applications
     */
    private function resources(Release $release, Collection $members, Collection $applications): PreflightResult
    {
        $problems = [];
        foreach ($members as $member) {
            $application = $member->resource_type === Application::class ? $applications->get($member->resource_id) : null;
            if ($application === null) {
                $problems[] = "{$member->resource_name} no longer exists or is not an application.";

                continue;
            }
            if ((int) $application->environment_id !== (int) $release->group->environment_id || (int) $application->environment?->project?->team_id !== (int) $release->team_id) {
                $problems[] = "{$member->resource_name} was moved out of this environment.";
            }
        }

        return new PreflightResult(
            id: 'resources',
            label: 'Resources',
            state: $problems === [] ? PreflightState::PASSED : PreflightState::FAILED,
            summary: $problems === [] ? "All {$members->count()} resources exist in this environment." : implode(' ', $problems),
            remediation: $problems === [] ? null : 'Remove the missing resources from the release group and start a new release.',
            category: 'release',
        );
    }

    /**
     * @param  Collection<int, ReleaseMember>  $members
     */
    private function dependencies(Collection $members): PreflightResult
    {
        $errors = (new ReleaseGraph($members->mapWithKeys(fn (ReleaseMember $member) => [
            $member->group_member_uuid => ['name' => $member->resource_name, 'phase' => $member->phase, 'depends_on' => $member->dependencies()],
        ])->all()))->errors();

        return new PreflightResult(
            id: 'dependencies',
            label: 'Order and dependencies',
            state: $errors === [] ? PreflightState::PASSED : PreflightState::FAILED,
            summary: $errors === [] ? 'Phases and dependencies are consistent.' : implode(' ', $errors),
            remediation: $errors === [] ? null : 'Fix the dependencies in the release group.',
            category: 'release',
        );
    }

    /**
     * @param  Collection<int, ReleaseMember>  $members
     * @param  Collection<int, Application>  $applications
     */
    private function servers(Collection $members, Collection $applications): PreflightResult
    {
        $unavailable = [];
        foreach ($members as $member) {
            $server = $applications->get($member->resource_id)?->destination?->server;
            if ($server !== null && ! $server->isFunctional()) {
                $unavailable[$server->name] = $server->name;
            }
        }

        return new PreflightResult(
            id: 'servers',
            label: 'Servers',
            state: $unavailable === [] ? PreflightState::PASSED : PreflightState::FAILED,
            summary: $unavailable === [] ? 'Every target server is reachable and usable.' : 'Not reachable or not usable: '.implode(', ', $unavailable).'.',
            remediation: $unavailable === [] ? null : 'Validate the server connection, then continue the release.',
            category: 'release',
        );
    }

    /**
     * @param  Collection<int, ReleaseMember>  $members
     */
    private function overlappingReleases(Release $release, Collection $members): PreflightResult
    {
        $overlapping = ReleaseMember::query()
            ->whereIn('resource_id', $members->pluck('resource_id'))
            ->where('resource_type', Application::class)
            ->where('release_id', '!=', $release->id)
            ->whereHas('release', fn ($query) => $query->active()->where('team_id', $release->team_id))
            ->with('release.group')
            ->get()
            ->map(fn (ReleaseMember $member) => $member->release?->group?->name)
            ->filter()
            ->unique()
            ->values();

        return new PreflightResult(
            id: 'overlapping_releases',
            label: 'Other releases',
            state: $overlapping->isEmpty() ? PreflightState::PASSED : PreflightState::WARNING,
            summary: $overlapping->isEmpty() ? 'No other running release contains these resources.' : 'Also part of a running release: '.$overlapping->implode(', ').'. Deployments of the same resource run one after another.',
            required: false,
            category: 'release',
        );
    }

    /**
     * @param  Collection<int, Application>  $applications
     */
    private function deploymentQueues(Collection $applications): PreflightResult
    {
        $busy = [];
        foreach ($applications->map(fn (Application $application) => $application->destination?->server)->filter()->unique('id') as $server) {
            $limit = (int) ($server->settings->deployment_queue_limit ?? 25);
            $queued = ApplicationDeploymentQueue::query()
                ->where('server_id', $server->id)
                ->where('status', ApplicationDeploymentStatus::QUEUED->value)
                ->count();
            if ($queued >= max(1, $limit - 2)) {
                $busy[] = "{$server->name} ({$queued} of {$limit} queued)";
            }
        }

        return new PreflightResult(
            id: 'deployment_queues',
            label: 'Deployment queues',
            state: $busy === [] ? PreflightState::PASSED : PreflightState::WARNING,
            summary: $busy === [] ? 'The deployment queues have room.' : 'Almost full: '.implode(', ', $busy).'. Resources wait until there is room.',
            required: false,
            category: 'capacity',
        );
    }
}
