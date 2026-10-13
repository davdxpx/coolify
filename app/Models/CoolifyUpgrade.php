<?php

namespace App\Models;

use App\Enums\CoolifyUpgradeState;
use App\Services\Preflight\PreflightResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CoolifyUpgrade extends Model
{
    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_AUTOMATIC = 'automatic';

    public const TRIGGER_ROLLBACK = 'rollback';

    public const RUNNING_TIMEOUT_MINUTES = 45;

    protected $fillable = [
        'uuid',
        'distribution',
        'channel',
        'trigger',
        'state',
        'from_version',
        'from_base_version',
        'from_build',
        'from_commit',
        'from_digest',
        'from_latest_migration',
        'to_version',
        'to_base_version',
        'to_build',
        'to_commit',
        'to_image',
        'to_digest',
        'to_latest_migration',
        'includes_migrations',
        'preflight',
        'acknowledged_checks',
        'acknowledgement_reason',
        'requested_by_user_id',
        'message',
        'latest_migration_after',
        'started_at',
        'finished_at',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => CoolifyUpgradeState::class,
            'from_build' => 'integer',
            'to_build' => 'integer',
            'includes_migrations' => 'boolean',
            'preflight' => 'array',
            'acknowledged_checks' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $upgrade) {
            $upgrade->uuid ??= (string) Str::uuid();
        });
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('state', CoolifyUpgradeState::RUNNING->value);
    }

    public function isActive(): bool
    {
        return $this->state === CoolifyUpgradeState::RUNNING;
    }

    public function hasTimedOut(): bool
    {
        return $this->isActive()
            && $this->started_at !== null
            && $this->started_at->lt(now()->subMinutes(self::RUNNING_TIMEOUT_MINUTES));
    }

    public function targetReference(): string
    {
        return "{$this->to_image}@{$this->to_digest}";
    }

    /**
     * @return list<PreflightResult>
     */
    public function preflightResults(): array
    {
        return array_map(
            fn (array $result) => PreflightResult::fromArray($result),
            array_values(array_filter($this->preflight ?? [], 'is_array')),
        );
    }

    public function finish(CoolifyUpgradeState $state, ?string $message = null): void
    {
        $this->forceFill([
            'state' => $state,
            'message' => $message ?? $this->message,
            'finished_at' => $this->finished_at ?? now(),
            'verified_at' => $state === CoolifyUpgradeState::SUCCEEDED ? now() : $this->verified_at,
        ])->save();
    }
}
