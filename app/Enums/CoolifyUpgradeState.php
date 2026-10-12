<?php

namespace App\Enums;

enum CoolifyUpgradeState: string
{
    case PREFLIGHT_FAILED = 'preflight_failed';
    case RUNNING = 'running';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case REVERTED = 'reverted';
    case NEEDS_RECOVERY = 'needs_recovery';
    case UNVERIFIED = 'unverified';

    public function isTerminal(): bool
    {
        return $this !== self::RUNNING;
    }

    public function label(): string
    {
        return match ($this) {
            self::PREFLIGHT_FAILED => 'Blocked by checks',
            self::RUNNING => 'Running',
            self::SUCCEEDED => 'Succeeded',
            self::FAILED => 'Failed, previous version kept',
            self::REVERTED => 'Failed, previous image restored',
            self::NEEDS_RECOVERY => 'Failed, recovery required',
            self::UNVERIFIED => 'Result not verified',
        };
    }

    public function badgeType(): string
    {
        return match ($this) {
            self::SUCCEEDED => 'success',
            self::RUNNING, self::UNVERIFIED, self::REVERTED => 'warning',
            self::FAILED, self::NEEDS_RECOVERY => 'error',
            self::PREFLIGHT_FAILED => 'neutral',
        };
    }
}
