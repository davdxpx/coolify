<?php

namespace App\Enums;

enum PreflightState: string
{
    case PASSED = 'passed';
    case WARNING = 'warning';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';
    case UNKNOWN = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::PASSED => 'Passed',
            self::WARNING => 'Warning',
            self::FAILED => 'Failed',
            self::SKIPPED => 'Skipped',
            self::UNKNOWN => 'Unknown',
        };
    }

    public function badgeType(): string
    {
        return match ($this) {
            self::PASSED => 'success',
            self::WARNING, self::UNKNOWN => 'warning',
            self::FAILED => 'error',
            self::SKIPPED => 'neutral',
        };
    }
}
