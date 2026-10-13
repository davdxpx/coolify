<?php

namespace App\Livewire\Project\Release;

use App\Actions\Release\CancelRelease;
use App\Actions\Release\CreateRelease;
use App\Actions\Release\ResumeRelease;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseGroup;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    #[Locked]
    public Project $project;

    #[Locked]
    public Environment $environment;

    #[Locked]
    public ReleaseGroup $group;

    #[Locked]
    public Release $release;

    /** @var list<string> */
    public array $overrideChecks = [];

    public string $overrideReason = '';

    public function mount(string $project_uuid, string $environment_uuid, string $release_group_uuid, string $release_uuid)
    {
        try {
            $this->project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
            $this->environment = $this->project->environments()->where('uuid', $environment_uuid)->firstOrFail();
            $this->group = ReleaseGroup::ownedByTeam(currentTeam()->id)
                ->where('environment_id', $this->environment->id)
                ->where('uuid', $release_group_uuid)
                ->firstOrFail();
            $this->release = $this->group->releases()->where('uuid', $release_uuid)->firstOrFail();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function cancel()
    {
        try {
            $this->release = CancelRelease::run($this->release, auth()->user());
            $this->dispatch('success', 'Release cancelled.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function resume(string $mode)
    {
        try {
            $this->release = ResumeRelease::run($this->release, $mode, auth()->user());
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function override()
    {
        try {
            $this->validate(['overrideChecks' => 'required|array|min:1', 'overrideChecks.*' => 'string', 'overrideReason' => 'required|string|max:500']);
            $this->release = ResumeRelease::run($this->release, ResumeRelease::OVERRIDE, auth()->user(), 'ui', $this->overrideChecks, $this->overrideReason);
            $this->reset('overrideChecks', 'overrideReason');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function runAgain()
    {
        try {
            $release = CreateRelease::run($this->group, Release::TRIGGER_RETRY, auth()->user(), $this->release->requested_commit, $this->release->notes, null, $this->release);

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

    public function render()
    {
        $this->release->refresh();
        $members = $this->release->members()->get();
        $applications = Application::query()
            ->whereIn('id', $members->where('resource_type', Application::class)->pluck('resource_id'))
            ->get()
            ->keyBy('id');

        return view('livewire.project.release.show', [
            'members' => $members,
            'applications' => $applications,
            'preflight' => $this->release->preflightResults(),
            'memberNames' => $members->pluck('resource_name', 'group_member_uuid'),
        ]);
    }
}
