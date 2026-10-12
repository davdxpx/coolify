<?php

namespace App\Services\Deployment;

use App\Models\Application;
use App\Models\DeploymentFreeze;

/**
 * The single place that decides whether a deployment may start during a freeze. It runs inside
 * queue_application_deployment(), so webhooks, the API, the UI, MCP and releases are all covered.
 */
class DeploymentFreezeGuard
{
    public function activeFreezeFor(Application $application): ?DeploymentFreeze
    {
        $environment = $application->environment;
        $teamId = $environment?->project?->team_id;
        if ($teamId === null) {
            return null;
        }

        return DeploymentFreeze::query()
            ->active()
            ->covering((int) $teamId, (int) $environment->project_id, (int) $environment->id)
            ->orderByRaw('allow_override asc')
            ->first();
    }

    /**
     * The freeze that stops this deployment, or null when it may start. Overrides are audited.
     */
    public function blockingFreeze(Application $application, bool $rollback = false, ?string $overrideReason = null, ?string $deploymentUuid = null): ?DeploymentFreeze
    {
        $freeze = $this->activeFreezeFor($application);
        if ($freeze === null || ($rollback && $freeze->allow_rollbacks)) {
            return null;
        }

        $overrideReason = filled($overrideReason) ? trim($overrideReason) : null;
        if ($overrideReason !== null && $freeze->allow_override) {
            auditLog('deployment.freeze_overridden', [
                'team_id' => $freeze->team_id,
                'freeze_uuid' => $freeze->uuid,
                'application_uuid' => $application->uuid,
                'deployment_uuid' => $deploymentUuid,
                'reason' => $overrideReason,
            ], 'warning');

            return null;
        }

        auditLog('system.deployment.freeze_blocked', [
            'team_id' => $freeze->team_id,
            'freeze_uuid' => $freeze->uuid,
            'application_uuid' => $application->uuid,
        ], 'warning');

        return $freeze;
    }

    public static function describe(DeploymentFreeze $freeze): string
    {
        return $freeze->describe().($freeze->allow_override ? ' A release can override the freeze with an audited reason.' : '');
    }
}
