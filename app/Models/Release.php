<?php

namespace App\Models;

use App\Enums\ReleaseFailurePolicy;
use App\Enums\ReleaseState;
use App\Services\Preflight\PreflightResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'One run of a release group.',
    type: 'object',
    properties: [
        'uuid' => ['type' => 'string'],
        'release_group_uuid' => ['type' => 'string'],
        'state' => ['type' => 'string', 'enum' => ['created', 'validating', 'running', 'attention_required', 'completed', 'partially_failed', 'failed', 'cancelled']],
        'trigger' => ['type' => 'string', 'enum' => ['ui', 'api', 'retry']],
        'correlation_id' => ['type' => 'string'],
        'requested_commit' => ['type' => 'string', 'nullable' => true],
        'failure_summary' => ['type' => 'string', 'nullable' => true],
        'attention_reason' => ['type' => 'string', 'nullable' => true],
        'acknowledged_checks' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Failed checks that were overridden with a reason.'],
        'created_at' => ['type' => 'string', 'format' => 'date-time'],
        'started_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
        'finished_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
        'members' => ['type' => 'array', 'items' => new OA\Items(ref: '#/components/schemas/ReleaseMember')],
    ],
)]
class Release extends BaseModel
{
    public const TRIGGER_UI = 'ui';

    public const TRIGGER_API = 'api';

    public const TRIGGER_RETRY = 'retry';

    protected $fillable = [
        'uuid',
        'release_group_id',
        'team_id',
        'state',
        'trigger',
        'idempotency_key',
        'correlation_id',
        'requested_commit',
        'notes',
        'requested_by_user_id',
        'source_release_id',
        'policy_snapshot',
        'preflight',
        'acknowledged_checks',
        'acknowledgement_reason',
        'summary',
        'failure_summary',
        'attention_reason',
        'validated_at',
        'started_at',
        'finished_at',
        'cancelled_at',
        'cancelled_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'state' => ReleaseState::class,
            'policy_snapshot' => 'array',
            'preflight' => 'array',
            'acknowledged_checks' => 'array',
            'summary' => 'array',
            'validated_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('state', ReleaseState::activeValues());
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ReleaseGroup::class, 'release_group_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(ReleaseMember::class)->orderBy('phase')->orderBy('id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function sourceRelease(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_release_id');
    }

    public function failurePolicy(): ReleaseFailurePolicy
    {
        return ReleaseFailurePolicy::tryFrom((string) data_get($this->policy_snapshot, 'failure_policy')) ?? ReleaseFailurePolicy::STOP;
    }

    public function maxParallel(): int
    {
        return max(1, min(ReleaseGroup::MAX_PARALLEL_LIMIT, (int) data_get($this->policy_snapshot, 'max_parallel', 1)));
    }

    public function requiresHealthy(): bool
    {
        return (bool) data_get($this->policy_snapshot, 'require_healthy', true);
    }

    public function verificationTimeoutSeconds(): int
    {
        return max(60, (int) data_get($this->policy_snapshot, 'verification_timeout_seconds', 600));
    }

    /**
     * @return list<string>
     */
    public function acknowledgedChecks(): array
    {
        return array_values(array_filter((array) $this->acknowledged_checks, 'is_string'));
    }

    /**
     * @return list<PreflightResult>
     */
    public function preflightResults(): array
    {
        return array_map(fn (array $check) => PreflightResult::fromArray($check), (array) $this->preflight);
    }
}
