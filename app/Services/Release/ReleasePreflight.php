<?php

namespace App\Services\Release;

use App\Enums\PreflightState;
use App\Models\Application;
use App\Models\ConfigurationContract;
use App\Models\DeploymentFreeze;
use App\Models\Release;
use App\Models\ReleaseMember;
use App\Services\Backup\BackupFreshness;
use App\Services\Configuration\ConfigurationReport;
use App\Services\Configuration\SharedVariableGraph;
use App\Services\Deployment\BuildCapacity;
use App\Services\Deployment\DeploymentFreezeGuard;
use App\Services\Preflight\PreflightResult;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
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

        $policy = (array) ($release->group->preflight_policy ?? []);

        return array_values(array_filter([
            $this->resources($release, $members, $applications),
            $this->dependencies($members),
            $this->servers($members, $applications),
            $this->deploymentFreeze($applications),
            $this->configuration($release, $applications),
            isset($policy['release_window']) ? $this->releaseWindow((array) $policy['release_window']) : null,
            isset($policy['backup_max_age_hours']) ? $this->backups($release, (int) $policy['backup_max_age_hours']) : null,
            $this->overlappingReleases($release, $members),
            $this->deploymentQueues($applications),
        ]));
    }

    /**
     * Required keys of configuration contracts and references to shared variables that do not exist.
     *
     * @param  Collection<int, Application>  $applications
     */
    private function configuration(Release $release, Collection $applications): PreflightResult
    {
        $edges = (new SharedVariableGraph((int) $release->team_id))->edges((int) $release->group->environment_id);
        $contracts = ConfigurationContract::query()->whereIn('application_id', $applications->pluck('id'))->get()->keyBy('application_id');
        $report = new ConfigurationReport;
        $problems = [];
        foreach ($applications as $application) {
            $applicationEdges = $edges->where('resource_uuid', $application->uuid);
            foreach ($applicationEdges->where('missing', true) as $edge) {
                $problems[] = "{$application->name}: {$edge['variable_key']} refers to {$edge['shared_type']}.{$edge['shared_key']}, which does not exist.";
            }
            $contract = $contracts->get($application->id);
            if ($contract !== null) {
                $check = $report->checkContract($contract, $application->environment_variables()->where('is_preview', false)->get(), $applicationEdges);
                if ($check['missing'] !== []) {
                    $problems[] = "{$application->name} misses ".implode(', ', $check['missing']).'.';
                }
                if ($check['empty'] !== []) {
                    $problems[] = "{$application->name} has empty ".implode(', ', $check['empty']).'.';
                }
            }
        }

        return new PreflightResult(
            id: 'configuration',
            label: 'Configuration',
            state: $problems === [] ? PreflightState::PASSED : PreflightState::FAILED,
            summary: $problems === [] ? 'Required variables are set and every shared variable reference exists.' : implode(' ', array_unique($problems)),
            overridable: true,
            remediation: $problems === [] ? null : 'Set the missing variables, or continue with an audited reason.',
            category: 'configuration',
        );
    }

    /**
     * @param  Collection<int, Application>  $applications
     */
    private function deploymentFreeze(Collection $applications): PreflightResult
    {
        $guard = app(DeploymentFreezeGuard::class);
        $freezes = $applications->map(fn (Application $application) => $guard->activeFreezeFor($application))->filter()->unique('id');
        if ($freezes->isEmpty()) {
            return new PreflightResult(id: 'deployment_freeze', label: 'Deployment freeze', state: PreflightState::PASSED, summary: 'No deployment freeze is active.', category: 'policy');
        }

        return new PreflightResult(
            id: 'deployment_freeze',
            label: 'Deployment freeze',
            state: PreflightState::FAILED,
            summary: $freezes->map(fn (DeploymentFreeze $freeze) => $freeze->describe())->implode(' '),
            overridable: $freezes->every(fn (DeploymentFreeze $freeze) => $freeze->allow_override),
            remediation: 'Wait until the freeze ends, or continue with an audited reason if the freeze allows it.',
            details: ['freezes' => $freezes->pluck('uuid')->implode(',')],
            category: 'policy',
        );
    }

    /**
     * @param  array{days?: list<int>, start?: string, end?: string, timezone?: string}  $window
     */
    public function releaseWindow(array $window, ?CarbonInterface $at = null): PreflightResult
    {
        $timezone = in_array($window['timezone'] ?? 'UTC', timezone_identifiers_list(), true) ? $window['timezone'] : 'UTC';
        $now = ($at ?? now())->copy()->setTimezone($timezone);
        $days = array_map('intval', (array) ($window['days'] ?? [1, 2, 3, 4, 5]));
        $start = (string) ($window['start'] ?? '00:00');
        $end = (string) ($window['end'] ?? '23:59');
        $time = $now->format('H:i');
        $inside = in_array((int) $now->format('N'), $days, true) && $time >= $start && $time <= $end;
        $dayNames = collect($days)->sort()->map(fn (int $day) => Carbon::create(2024, 1, $day)->format('D'))->implode(', ');

        return new PreflightResult(
            id: 'release_window',
            label: 'Release window',
            state: $inside ? PreflightState::PASSED : PreflightState::FAILED,
            summary: ($inside ? 'Inside the release window' : 'Outside the release window')." ({$dayNames}, {$start} to {$end} {$timezone}).",
            overridable: true,
            remediation: $inside ? null : 'Start the release inside the window, or continue with an audited reason.',
            category: 'policy',
        );
    }

    private function backups(Release $release, int $maxAgeHours): PreflightResult
    {
        $statuses = app(BackupFreshness::class)->forEnvironment($release->group->environment);
        $problems = [];
        foreach ($statuses as $status) {
            if (! $status['configured']) {
                $problems[] = "{$status['database_name']} has no enabled backup schedule.";
            } elseif ($status['age_hours'] === null || $status['age_hours'] > $maxAgeHours) {
                $problems[] = "{$status['database_name']} has no successful backup in the last {$maxAgeHours} hours.";
            }
        }

        return new PreflightResult(
            id: 'backup_freshness',
            label: 'Database backups',
            state: $problems === [] ? PreflightState::PASSED : PreflightState::FAILED,
            summary: $problems === [] ? ($statuses === [] ? 'The environment has no databases.' : "Every database has a successful backup from the last {$maxAgeHours} hours.") : implode(' ', $problems),
            overridable: true,
            remediation: $problems === [] ? null : 'Run a backup of the listed databases, or continue with an audited reason.',
            category: 'policy',
        );
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
            $capacity = BuildCapacity::forServer($server);
            if ($capacity->queueAlmostFull()) {
                $busy[] = "{$server->name} ({$capacity->queued} of {$capacity->queueLimit} queued)";
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
