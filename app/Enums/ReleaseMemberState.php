<?php

namespace App\Enums;

enum ReleaseMemberState: string
{
    case PENDING = 'pending';
    case DISPATCHING = 'dispatching';
    case QUEUED = 'queued';
    case DEPLOYING = 'deploying';
    case VERIFYING = 'verifying';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';
    case CANCELLED = 'cancelled';

    /**
     * @return list<string>
     */
    public static function inFlightValues(): array
    {
        return array_values(array_map(fn (self $state) => $state->value, array_filter(self::cases(), fn (self $state) => $state->isInFlight())));
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::SUCCEEDED, self::FAILED, self::SKIPPED, self::CANCELLED], true);
    }

    public function isInFlight(): bool
    {
        return in_array($this, [self::DISPATCHING, self::QUEUED, self::DEPLOYING, self::VERIFYING], true);
    }

    public function isUnsuccessful(): bool
    {
        return in_array($this, [self::FAILED, self::SKIPPED, self::CANCELLED], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::DISPATCHING => 'Starting',
            self::QUEUED => 'Queued',
            self::DEPLOYING => 'Deploying',
            self::VERIFYING => 'Verifying',
            self::SUCCEEDED => 'Succeeded',
            self::FAILED => 'Failed',
            self::SKIPPED => 'Skipped',
            self::CANCELLED => 'Cancelled',
        };
    }

    public function badgeType(): string
    {
        return match ($this) {
            self::SUCCEEDED => 'success',
            self::DISPATCHING, self::QUEUED, self::DEPLOYING, self::VERIFYING => 'warning',
            self::FAILED => 'error',
            self::PENDING, self::SKIPPED, self::CANCELLED => 'neutral',
        };
    }
}
