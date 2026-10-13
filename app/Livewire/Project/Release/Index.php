<?php

namespace App\Livewire\Project\Release;

use App\Models\Application;
use App\Models\DeploymentFreeze;
use App\Models\Environment;
use App\Models\Project;
use App\Models\ReleaseGroup;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Services\Deployment\BuildCapacity;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public Project $project;

    #[Locked]
    public Environment $environment;

    public string $name = '';

    public ?string $description = null;

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
        ];
    }

    protected function messages(): array
    {
        return ValidationPatterns::combinedMessages();
    }

    public function mount(string $project_uuid, string $environment_uuid)
    {
        try {
            $this->project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
            $this->environment = $this->project->environments()->where('uuid', $environment_uuid)->firstOrFail();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function create()
    {
        try {
            $this->authorize('create', ReleaseGroup::class);
            $this->validate();
            $group = ReleaseGroup::create([
                'team_id' => currentTeam()->id,
                'environment_id' => $this->environment->id,
                'name' => $this->name,
                'description' => $this->description,
            ]);
            auditLog('ui.release_group.created', ['team_id' => $group->team_id, 'release_group_uuid' => $group->uuid, 'name' => $group->name]);

            return redirectRoute($this, 'project.release-group.show', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'release_group_uuid' => $group->uuid,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        $groups = ReleaseGroup::ownedByTeam(currentTeam()->id)
            ->where('environment_id', $this->environment->id)
            ->withCount('members')
            ->with(['releases' => fn ($query) => $query->limit(1)])
            ->orderBy('name')
            ->get();

        $servers = Server::query()
            ->whereIn('id', StandaloneDocker::query()->whereIn('id', Application::query()->where('environment_id', $this->environment->id)->where('destination_type', StandaloneDocker::class)->pluck('destination_id'))->pluck('server_id'))
            ->with('settings')
            ->get();
        $freezes = DeploymentFreeze::query()
            ->upcomingOrActive()
            ->covering((int) currentTeam()->id, (int) $this->project->id, (int) $this->environment->id)
            ->orderBy('starts_at')
            ->get();

        return view('livewire.project.release.index', [
            'groups' => $groups,
            'capacities' => $servers->map(fn (Server $server) => BuildCapacity::forServer($server)),
            'freezes' => $freezes,
        ]);
    }
}
