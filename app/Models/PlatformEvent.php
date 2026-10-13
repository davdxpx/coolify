<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Something that happened in a team, in the order it was recorded. Never contains secrets.',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer', 'description' => 'Increasing cursor for the events feed.'],
        'uuid' => ['type' => 'string'],
        'type' => ['type' => 'string', 'example' => 'release.completed'],
        'subject_type' => ['type' => 'string', 'example' => 'release'],
        'subject_uuid' => ['type' => 'string', 'nullable' => true],
        'correlation_id' => ['type' => 'string', 'nullable' => true],
        'data' => ['type' => 'object'],
        'occurred_at' => ['type' => 'string', 'format' => 'date-time'],
    ],
)]
class PlatformEvent extends BaseModel
{
    public const TYPES = [
        'release.started',
        'release.paused',
        'release.completed',
        'release.partially_failed',
        'release.failed',
        'release.cancelled',
        'deployment.finished',
        'deployment.failed',
        'deployment.cancelled',
        'deployment_freeze.created',
        'deployment_freeze.ended',
        'ping',
    ];

    public const RETENTION_DAYS = 30;

    protected $fillable = ['uuid', 'team_id', 'type', 'subject_type', 'subject_uuid', 'correlation_id', 'data', 'occurred_at'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(EventDelivery::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'type' => $this->type,
            'subject_type' => $this->subject_type,
            'subject_uuid' => $this->subject_uuid,
            'correlation_id' => $this->correlation_id,
            'data' => $this->data,
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}
