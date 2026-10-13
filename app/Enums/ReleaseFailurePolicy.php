<?php

namespace App\Enums;

enum ReleaseFailurePolicy: string
{
    case STOP = 'stop';
    case CONTINUE_INDEPENDENT = 'continue_independent';
    case MANUAL = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::STOP => 'Stop the release',
            self::CONTINUE_INDEPENDENT => 'Continue with independent resources',
            self::MANUAL => 'Pause and wait for a decision',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::STOP => 'No further resource is started. Deployments already running finish.',
            self::CONTINUE_INDEPENDENT => 'Resources that depend on the failed one are skipped, all others continue.',
            self::MANUAL => 'The release pauses until someone retries the failed resources or cancels the release.',
        };
    }
}
