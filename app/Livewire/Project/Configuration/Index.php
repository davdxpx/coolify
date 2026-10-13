<?php

namespace App\Livewire\Project\Configuration;

use App\Models\Environment;
use App\Models\Project;
use App\Services\Configuration\ConfigurationReport;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Index extends Component
{
    #[Locked]
    public Project $project;

    #[Locked]
    public Environment $environment;

    public function mount(string $project_uuid, string $environment_uuid)
    {
        try {
            $this->project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
            $this->environment = $this->project->environments()->where('uuid', $environment_uuid)->firstOrFail();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        $this->environment->setRelation('project', $this->project);

        return view('livewire.project.configuration.index', [
            'report' => (new ConfigurationReport)->forEnvironment($this->environment),
        ]);
    }
}
