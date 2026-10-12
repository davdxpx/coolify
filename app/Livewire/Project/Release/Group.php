<?php

namespace App\Livewire\Project\Release;

use App\Actions\Release\CreateRelease;
use App\Enums\ReleaseFailurePolicy;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseGroup;
use App\Models\ReleaseGroupMember;
use App\Services\Release\ReleaseGraph;
use App\Services\Release\ReleaseRefused;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Group extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public Project $project;

    #[Locked]
    public Environment $environment;

    #[Locked]
    public ReleaseGroup $group;

    public string $name = '';

    public ?string $description = null;

    public bool $isEnabled = true;

    public string $failurePolicy = 'stop';

    public int $maxParallel = 2;

    public bool $requireHealthy = true;

    public int $verificationTimeoutSeconds = 600;

    /** @var array<string, array{phase: int, is_required: bool, force_rebuild: bool, is_enabled: bool, depends_on: list<string>}> */
    public array $memberSettings = [];

    public ?string $newApplicationUuid = null;

    public int $newPhase = 1;

    public ?string $releaseCommit = null;

    public ?string $releaseNotes = null;

    public function mount(string $project_uuid, string $environment_uuid, string $release_group_uuid)
    {
        try {
            $this->project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
            $this->environment = $this->project->environments()->where('uuid', $environment_uuid)->firstOrFail();
            $this->group = ReleaseGroup::ownedByTeam(currentTeam()->id)
                ->where('environment_id', $this->environment->id)
                ->where('uuid', $release_group_uuid)
                ->firstOrFail();
            $this->syncSettings();
            $this->syncMembers();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function syncSettings(): void
    {
        $this->name = $this->group->name;
        $this->description = $this->group->description;
        $this->isEnabled = $this->group->is_enabled;
        $this->failurePolicy = $this->group->failure_policy->value;
        $this->maxParallel = $this->group->max_parallel;
        $this->requireHealthy = $this->group->require_healthy;
        $this->verificationTimeoutSeconds = $this->group->verification_timeout_seconds;
    }

    private function syncMembers(): void
    {
        $this->memberSettings = $this->group->members()->get()->mapWithKeys(fn (ReleaseGroupMember $member) => [
            $member->uuid => [
                'phase' => $member->phase,
                'is_required' => $member->is_required,
                'force_rebuild' => $member->force_rebuild,
                'is_enabled' => $member->is_enabled,
                'depends_on' => $member->dependencies(),
            ],
        ])->all();
    }

    public function saveSettings()
    {
        try {
            $this->authorize('update', $this->group);
            $this->validate([
                'name' => ValidationPatterns::nameRules(),
                'description' => ValidationPatterns::descriptionRules(),
                'isEnabled' => 'boolean',
                'failurePolicy' => ['required', Rule::enum(ReleaseFailurePolicy::class)],
                'maxParallel' => 'required|integer|min:1|max:'.ReleaseGroup::MAX_PARALLEL_LIMIT,
                'requireHealthy' => 'boolean',
                'verificationTimeoutSeconds' => 'required|integer|min:60|max:7200',
            ], ValidationPatterns::combinedMessages());

            $this->group->update([
                'name' => $this->name,
                'description' => $this->description,
                'is_enabled' => $this->isEnabled,
                'failure_policy' => $this->failurePolicy,
                'max_parallel' => $this->maxParallel,
                'require_healthy' => $this->requireHealthy,
                'verification_timeout_seconds' => $this->verificationTimeoutSeconds,
            ]);
            auditLog('ui.release_group.updated', ['team_id' => $this->group->team_id, 'release_group_uuid' => $this->group->uuid]);
            $this->dispatch('success', 'Release group saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function addMember()
    {
        try {
            $this->authorize('update', $this->group);
            $this->validate([
                'newApplicationUuid' => 'required|string',
                'newPhase' => 'required|integer|min:1|max:20',
            ]);
            $application = Application::query()
                ->where('environment_id', $this->environment->id)
                ->where('uuid', $this->newApplicationUuid)
                ->firstOrFail();
            $this->authorize('deploy', $application);

            if ($this->group->members()->count() >= ReleaseGroup::MAX_MEMBERS) {
                throw new ReleaseRefused('A release group can contain at most '.ReleaseGroup::MAX_MEMBERS.' resources.');
            }
            if ($this->group->members()->where('resource_type', Application::class)->where('resource_id', $application->id)->exists()) {
                throw new ReleaseRefused("{$application->name} is already part of this group.");
            }

            $this->group->members()->create([
                'resource_type' => Application::class,
                'resource_id' => $application->id,
                'phase' => $this->newPhase,
                'position' => (int) $this->group->members()->max('position') + 1,
            ]);
            auditLog('ui.release_group.member_added', ['team_id' => $this->group->team_id, 'release_group_uuid' => $this->group->uuid, 'application_uuid' => $application->uuid]);
            $this->newApplicationUuid = null;
            $this->syncMembers();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function saveMembers()
    {
        try {
            $this->authorize('update', $this->group);
            $members = $this->group->members()->get()->keyBy('uuid');
            $names = $this->memberNames($members);

            $nodes = [];
            foreach ($members as $uuid => $member) {
                $settings = $this->memberSettings[$uuid] ?? null;
                if (! is_array($settings)) {
                    continue;
                }
                $phase = (int) ($settings['phase'] ?? 1);
                if ($phase < 1 || $phase > 20) {
                    throw new ReleaseRefused('Phases go from 1 to 20.');
                }
                $nodes[$uuid] = [
                    'name' => $names[$uuid] ?? 'A removed resource',
                    'phase' => $phase,
                    'depends_on' => array_values(array_filter((array) ($settings['depends_on'] ?? []), 'is_string')),
                    'enabled' => (bool) ($settings['is_enabled'] ?? true),
                ];
            }

            $errors = (new ReleaseGraph($nodes))->errors();
            if ($errors !== []) {
                throw new ReleaseRefused(implode(' ', $errors));
            }

            DB::transaction(function () use ($members, $nodes) {
                foreach ($nodes as $uuid => $node) {
                    $settings = $this->memberSettings[$uuid];
                    $members[$uuid]->update([
                        'phase' => $node['phase'],
                        'depends_on' => $node['depends_on'],
                        'is_enabled' => $node['enabled'],
                        'is_required' => (bool) ($settings['is_required'] ?? true),
                        'force_rebuild' => (bool) ($settings['force_rebuild'] ?? false),
                    ]);
                }
            });
            auditLog('ui.release_group.members_updated', ['team_id' => $this->group->team_id, 'release_group_uuid' => $this->group->uuid]);
            $this->syncMembers();
            $this->dispatch('success', 'Resources saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function removeMember(string $memberUuid)
    {
        try {
            $this->authorize('update', $this->group);
            $member = $this->group->members()->where('uuid', $memberUuid)->firstOrFail();
            $dependents = $this->group->members()->get()->filter(fn (ReleaseGroupMember $other) => in_array($memberUuid, $other->dependencies(), true));
            if ($dependents->isNotEmpty()) {
                throw new ReleaseRefused('Other resources depend on this one. Remove those dependencies first.');
            }
            $member->delete();
            auditLog('ui.release_group.member_removed', ['team_id' => $this->group->team_id, 'release_group_uuid' => $this->group->uuid, 'member_uuid' => $memberUuid]);
            $this->syncMembers();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function startRelease()
    {
        try {
            $this->validate([
                'releaseCommit' => 'nullable|string|max:255',
                'releaseNotes' => 'nullable|string|max:2000',
            ]);
            $release = CreateRelease::run($this->group, Release::TRIGGER_UI, auth()->user(), $this->releaseCommit, $this->releaseNotes);

            return redirectRoute($this, 'project.release.show', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'release_group_uuid' => $this->group->uuid,
                'release_uuid' => $release->uuid,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete()
    {
        try {
            $this->authorize('delete', $this->group);
            if ($this->group->activeRelease() !== null) {
                throw new ReleaseRefused('Cancel the running release before deleting the group.');
            }
            auditLog('ui.release_group.deleted', ['team_id' => $this->group->team_id, 'release_group_uuid' => $this->group->uuid, 'name' => $this->group->name]);
            $this->group->delete();

            return redirectRoute($this, 'project.release-group.index', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * @param  Collection<string, ReleaseGroupMember>  $members
     * @return array<string, string>
     */
    private function memberNames($members): array
    {
        $applications = Application::query()
            ->whereIn('id', $members->where('resource_type', Application::class)->pluck('resource_id'))
            ->pluck('name', 'id');

        return $members->map(fn (ReleaseGroupMember $member) => (string) ($applications[$member->resource_id] ?? 'A removed resource'))->all();
    }

    public function render()
    {
        $members = $this->group->members()->get();
        $applications = Application::query()
            ->whereIn('id', $members->where('resource_type', Application::class)->pluck('resource_id'))
            ->get()
            ->keyBy('id');
        $available = Application::query()
            ->where('environment_id', $this->environment->id)
            ->whereNotIn('id', $members->where('resource_type', Application::class)->pluck('resource_id'))
            ->orderBy('name')
            ->get(['uuid', 'name']);

        return view('livewire.project.release.group', [
            'members' => $members,
            'applications' => $applications,
            'availableApplications' => $available,
            'releases' => $this->group->releases()->with('requestedBy')->withCount('members')->limit(20)->get(),
            'activeRelease' => $this->group->activeRelease(),
            'policies' => ReleaseFailurePolicy::cases(),
        ]);
    }
}
