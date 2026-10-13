<?php

namespace App\Models;

use App\Enums\ReleaseFailurePolicy;
use App\Enums\ReleaseState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'A set of resources in one environment that are deployed together in a defined order.',
    type: 'object',
    properties: [
        'uuid' => ['type' => 'string'],
        'name' => ['type' => 'string'],
        'description' => ['type' => 'string', 'nullable' => true],
        'environment_uuid' => ['type' => 'string'],
        'is_enabled' => ['type' => 'boolean'],
        'failure_policy' => ['type' => 'string', 'enum' => ['stop', 'continue_independent', 'manual']],
        'max_parallel' => ['type' => 'integer'],
        'require_healthy' => ['type' => 'boolean'],
        'verification_timeout_seconds' => ['type' => 'integer'],
    ],
)]
class ReleaseGroup extends BaseModel
{
    public const MAX_MEMBERS = 50;

    public const MAX_PARALLEL_LIMIT = 10;

    protected $fillable = [
        'uuid',
        'team_id',
        'environment_id',
        'name',
        'description',
        'is_enabled',
        'failure_policy',
        'max_parallel',
        'require_healthy',
        'verification_timeout_seconds',
        'preflight_policy',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'failure_policy' => ReleaseFailurePolicy::class,
            'max_parallel' => 'integer',
            'require_healthy' => 'boolean',
            'verification_timeout_seconds' => 'integer',
            'preflight_policy' => 'array',
        ];
    }

    public static function ownedByTeam(int $teamId): Builder
    {
        return self::query()->where('team_id', $teamId);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(ReleaseGroupMember::class)->orderBy('phase')->orderBy('position')->orderBy('id');
    }

    public function releases(): HasMany
    {
        return $this->hasMany(Release::class)->latest('id');
    }

    public function activeRelease(): ?Release
    {
        return $this->releases()->whereIn('state', ReleaseState::activeValues())->first();
    }
}
