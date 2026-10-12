<?php

namespace App\Services\Deployment;

use App\Models\DeploymentFreeze;
use App\Services\Events\PlatformEvents;

class DeploymentFreezes
{
    public static function recordEvent(DeploymentFreeze $freeze, string $type): void
    {
        PlatformEvents::record($type, $freeze->team_id, 'deployment_freeze', $freeze->uuid, [
            'reason' => $freeze->reason,
            'scope' => $freeze->scope(),
            'project_uuid' => $freeze->project?->uuid,
            'environment_uuid' => $freeze->environment?->uuid,
            'starts_at' => $freeze->starts_at->toIso8601String(),
            'ends_at' => $freeze->ends_at?->toIso8601String(),
            'ended_at' => $freeze->ended_at?->toIso8601String(),
            'allow_rollbacks' => $freeze->allow_rollbacks,
            'allow_override' => $freeze->allow_override,
        ]);
    }
}
