<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'A period in which deployments of a team, project or environment are blocked.',
    type: 'object',
    properties: [
        'uuid' => ['type' => 'string'],
        'reason' => ['type' => 'string'],
        'scope' => ['type' => 'string', 'enum' => ['team', 'project', 'environment']],
        'project_uuid' => ['type' => 'string', 'nullable' => true],
        'environment_uuid' => ['type' => 'string', 'nullable' => true],
        'starts_at' => ['type' => 'string', 'format' => 'date-time'],
        'ends_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
        'allow_rollbacks' => ['type' => 'boolean'],
        'allow_override' => ['type' => 'boolean'],
        'is_active' => ['type' => 'boolean'],
    ],
)]
class DeploymentFreeze extends BaseModel
{
    protected $fillable = [
        'uuid',
        'team_id',
        'project_id',
        'environment_id',
        'reason',
        'starts_at',
        'ends_at',
        'allow_rollbacks',
        'allow_override',
        'created_by_user_id',
        'ended_at',
        'ended_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'ended_at' => 'datetime',
            'allow_rollbacks' => 'boolean',
            'allow_override' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at')
            ->where('starts_at', '<=', now())
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function scopeUpcomingOrActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at')
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /**
     * Freezes that cover a resource: team-wide ones, the ones of its project and of its environment.
     */
    public function scopeCovering(Builder $query, int $teamId, ?int $projectId, ?int $environmentId): Builder
    {
        return $query->where('team_id', $teamId)->where(function (Builder $query) use ($projectId, $environmentId) {
            $query->where(fn (Builder $query) => $query->whereNull('project_id')->whereNull('environment_id'));
            if ($projectId !== null) {
                $query->orWhere(fn (Builder $query) => $query->where('project_id', $projectId)->whereNull('environment_id'));
            }
            if ($environmentId !== null) {
                $query->orWhere('environment_id', $environmentId);
            }
        });
    }

    public function scope(): string
    {
        return match (true) {
            $this->environment_id !== null => 'environment',
            $this->project_id !== null => 'project',
            default => 'team',
        };
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && $this->starts_at->lte(now()) && ($this->ends_at === null || $this->ends_at->gt(now()));
    }

    public function describe(): string
    {
        $until = $this->ends_at ? ' until '.$this->ends_at->toDayDateTimeString() : ' until it is ended';

        return "Deployments are frozen{$until}: {$this->reason}";
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
