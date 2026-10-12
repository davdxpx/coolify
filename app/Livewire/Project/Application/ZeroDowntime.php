<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Services\Deployment\ComposeBlueGreen;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ZeroDowntime extends Component
{
    use AuthorizesRequests;

    public Application $application;

    public bool $isEnabled = false;

    public function mount(): void
    {
        $this->isEnabled = (bool) $this->application->settings->is_compose_blue_green_enabled;
    }

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->application);
            $report = app(ComposeBlueGreen::class)->analyze($this->application);
            if ($this->isEnabled && ! $report['eligible']) {
                $this->isEnabled = false;
                throw new \RuntimeException('This application cannot be deployed without downtime yet: '.implode(' ', $report['blocking']));
            }
            $this->application->settings->is_compose_blue_green_enabled = $this->isEnabled;
            $this->application->settings->save();
            auditLog('ui.application.zero_downtime_changed', [
                'team_id' => $this->application->team()?->id,
                'application_uuid' => $this->application->uuid,
                'enabled' => $this->isEnabled,
            ]);
            $this->dispatch('success', $this->isEnabled ? 'Zero-downtime deployments enabled.' : 'Zero-downtime deployments disabled.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.application.zero-downtime', [
            'report' => app(ComposeBlueGreen::class)->analyze($this->application),
            'canUpdate' => auth()->user()?->can('update', $this->application) ?? false,
        ]);
    }
}
