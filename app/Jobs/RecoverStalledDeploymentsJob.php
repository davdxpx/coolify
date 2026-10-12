<?php

namespace App\Jobs;

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\DeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecoverStalledDeploymentsJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MINIMUM_WAIT_SECONDS = 900;

    public const BATCH = 100;

    public $tries = 1;

    public function handle(): void
    {
        $candidates = ApplicationDeploymentQueue::query()
            ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->whereNotNull('admitted_at')
            ->whereNull('started_at')
            ->where('admitted_at', '<', now()->subSeconds(self::MINIMUM_WAIT_SECONDS))
            ->orderBy('admitted_at')
            ->limit(self::BATCH)
            ->get();

        foreach ($candidates as $deployment) {
            try {
                $this->recover($deployment);
            } catch (Throwable $e) {
                Log::warning("Recovering stalled deployment {$deployment->deployment_uuid} failed: {$e->getMessage()}");
            }
        }
    }

    public function recover(ApplicationDeploymentQueue $deployment): bool
    {
        $timeout = max(self::MINIMUM_WAIT_SECONDS, (int) ($deployment->server?->settings?->dynamic_timeout ?? 3600));
        if ($deployment->admitted_at === null || $deployment->admitted_at->gt(now()->subSeconds($timeout))) {
            return false;
        }

        if ($deployment->getHorizonJobStatus() === 'reserved') {
            return false;
        }

        $released = ApplicationDeploymentQueue::query()
            ->whereKey($deployment->id)
            ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->whereNull('started_at')
            ->update([
                'status' => ApplicationDeploymentStatus::FAILED->value,
                'finished_at' => now(),
            ]);

        if ($released === 0) {
            return false;
        }

        $deployment->refresh();
        $deployment->recordPhase(DeploymentPhase::FAILED);
        $deployment->addLogEntry("No worker started this deployment within {$timeout} seconds after its slot was reserved. The slot was released so other deployments can run. Check that Horizon is running.", 'stderr');

        auditLog('system.deployment.stalled_released', [
            'resource' => 'deployment',
            'deployment_uuid' => $deployment->deployment_uuid,
            'application_id' => $deployment->application_id,
            'admitted_at' => $deployment->admitted_at?->toIso8601String(),
        ], 'warning');

        $application = Application::query()->find($deployment->application_id);
        if ($application) {
            queue_next_deployment($application, $deployment->server_id);
        }

        return true;
    }
}
