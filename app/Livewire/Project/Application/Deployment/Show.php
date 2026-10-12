<?php

namespace App\Livewire\Project\Application\Deployment;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Traits\AuditsApplicationSettings;
use Illuminate\Support\Carbon;
use Livewire\Component;

class Show extends Component
{
    use AuditsApplicationSettings;

    public Application $application;

    public ApplicationDeploymentQueue $application_deployment_queue;

    public string $deployment_uuid;

    public string $horizon_job_status;

    public $isKeepAliveOn = true;

    public bool $is_debug_enabled = false;

    public bool $fullscreen = false;

    private bool $deploymentFinishedDispatched = false;

    public function getListeners()
    {
        return [
            'refreshQueue',
        ];
    }

    public function mount()
    {
        $deploymentUuid = request()->route('deployment_uuid');

        $project = currentTeam()->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('uuid', request()->route('environment_uuid'))->first();
        if (! $environment) {
            abort(404);
        }
        $environment->load(['applications']);
        $application = $environment->applications->where('uuid', request()->route('application_uuid'))->first();
        if (! $application) {
            return redirect()->route('dashboard');
        }
        $application_deployment_queue = ApplicationDeploymentQueue::where('deployment_uuid', $deploymentUuid)->first();
        if (! $application_deployment_queue) {
            return redirect()->route('project.application.deployment.index', [
                'project_uuid' => $project->uuid,
                'environment_uuid' => $environment->uuid,
                'application_uuid' => $application->uuid,
            ]);
        }
        $this->application = $application;
        $this->application_deployment_queue = $application_deployment_queue;
        $this->horizon_job_status = $this->application_deployment_queue->getHorizonJobStatus();
        $this->deployment_uuid = $deploymentUuid;
        $this->is_debug_enabled = auth()->user()->isMember()
            ? false
            : $this->application->settings->is_debug_enabled;
        $this->isKeepAliveOn();
    }

    public function toggleDebug()
    {
        try {
            $this->authorize('update', $this->application);
            $this->application->settings->is_debug_enabled = ! $this->application->settings->is_debug_enabled;
            $this->saveApplicationSettingsWithAudit($this->application);
            $this->is_debug_enabled = $this->application->settings->is_debug_enabled;
            $this->application_deployment_queue->refresh();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function refreshQueue()
    {
        $this->application_deployment_queue->refresh();
    }

    private function isKeepAliveOn()
    {
        if (data_get($this->application_deployment_queue, 'status') === 'finished' || data_get($this->application_deployment_queue, 'status') === 'failed') {
            $this->isKeepAliveOn = false;
        } else {
            $this->isKeepAliveOn = true;
        }
    }

    public function polling()
    {
        $this->application_deployment_queue->refresh();
        $this->horizon_job_status = $this->application_deployment_queue->getHorizonJobStatus();
        $this->isKeepAliveOn();

        // Dispatch event when deployment finishes to stop auto-scroll (only once)
        if (! $this->isKeepAliveOn && ! $this->deploymentFinishedDispatched) {
            $this->deploymentFinishedDispatched = true;
            $this->dispatch('deploymentFinished');
        }
    }

    public function getLogLinesProperty()
    {
        return decode_remote_command_output($this->application_deployment_queue);
    }

    public function downloadAllLogs(): string
    {
        $this->authorize('update', $this->application);

        $logs = decode_remote_command_output($this->application_deployment_queue, includeAll: true)
            ->map(function ($line) {
                $prefix = '';
                if ($line['hidden']) {
                    $prefix = '[DEBUG] ';
                }
                if (isset($line['command']) && $line['command']) {
                    $prefix .= '[CMD]: ';
                }

                return $line['timestamp'].' '.$prefix.trim($line['line']);
            })
            ->join("\n");

        return sanitizeLogsForExport($logs);
    }

    /**
     * @return array{state: array{label: string, type: string, description: string, phase: ?string}, timeline: array<string, Carbon>, blocking: ?string}
     */
    public function getExecutionSummaryProperty(): array
    {
        $deployment = $this->application_deployment_queue;
        $timeline = array_filter([
            'Requested' => $deployment->created_at,
            'Slot reserved' => $deployment->admitted_at,
            'Handed to worker' => $deployment->dispatched_at,
            'Worker started' => $deployment->started_at,
            'Finished' => $deployment->finished_at,
        ]);

        $blocking = null;
        if ($deployment->status === ApplicationDeploymentStatus::QUEUED->value) {
            $sameApplicationRunning = ApplicationDeploymentQueue::query()
                ->where('application_id', $deployment->application_id)
                ->where('pull_request_id', $deployment->pull_request_id)
                ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
                ->exists();
            $server = $deployment->server;
            $busy = ApplicationDeploymentQueue::query()
                ->where('server_id', $deployment->server_id)
                ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
                ->count();
            $blocking = $sameApplicationRunning
                ? 'Waiting for the running deployment of this application to finish.'
                : "Waiting for a deployment slot: {$busy} of ".($server?->settings?->concurrent_builds ?? '?').' slots on '.($server?->name ?? 'the server').' are in use.';
        }

        return ['state' => $deployment->displayState(), 'timeline' => $timeline, 'blocking' => $blocking, 'images' => (array) data_get($deployment->artifacts, 'images', [])];
    }

    public function render()
    {
        return view('livewire.project.application.deployment.show');
    }
}
