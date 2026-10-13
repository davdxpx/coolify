<?php

namespace App\Enums;

enum ReleaseState: string
{
    case CREATED = 'created';
    case VALIDATING = 'validating';
    case RUNNING = 'running';
    case ATTENTION_REQUIRED = 'attention_required';
    case COMPLETED = 'completed';
    case PARTIALLY_FAILED = 'partially_failed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(fn (self $state) => $state->value, array_filter(self::cases(), fn (self $state) => ! $state->isTerminal())));
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::PARTIALLY_FAILED, self::FAILED, self::CANCELLED], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::CREATED => 'Created',
            self::VALIDATING => 'Checking',
            self::RUNNING => 'Running',
            self::ATTENTION_REQUIRED => 'Needs attention',
            self::COMPLETED => 'Completed',
            self::PARTIALLY_FAILED => 'Partially failed',
            self::FAILED => 'Failed',
            self::CANCELLED => 'Cancelled',
        };
    }

    public function badgeType(): string
    {
        return match ($this) {
            self::COMPLETED => 'success',
            self::CREATED, self::VALIDATING, self::RUNNING, self::ATTENTION_REQUIRED, self::PARTIALLY_FAILED => 'warning',
            self::FAILED => 'error',
            self::CANCELLED => 'neutral',
        };
    }
}
