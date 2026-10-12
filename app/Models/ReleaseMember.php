<?php

namespace App\Models;

use App\Enums\ReleaseMemberState;
use App\Jobs\ProcessReleaseJob;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'The execution of one resource inside a release.',
    type: 'object',
    properties: [
        'uuid' => ['type' => 'string'],
        'resource_type' => ['type' => 'string', 'example' => 'application'],
        'resource_uuid' => ['type' => 'string', 'nullable' => true],
        'resource_name' => ['type' => 'string'],
        'phase' => ['type' => 'integer'],
        'is_required' => ['type' => 'boolean'],
        'depends_on' => ['type' => 'array', 'items' => ['type' => 'string']],
        'state' => ['type' => 'string', 'enum' => ['pending', 'dispatching', 'queued', 'deploying', 'verifying', 'succeeded', 'failed', 'skipped', 'cancelled']],
        'deployment_uuid' => ['type' => 'string', 'nullable' => true],
        'deployment_status' => ['type' => 'string', 'nullable' => true],
        'requested_commit' => ['type' => 'string', 'nullable' => true],
        'actual_commit' => ['type' => 'string', 'nullable' => true],
        'image_digest' => ['type' => 'string', 'nullable' => true],
        'failure_reason' => ['type' => 'string', 'nullable' => true],
        'note' => ['type' => 'string', 'nullable' => true, 'description' => 'Why the resource is still waiting.'],
        'attempts' => ['type' => 'integer'],
        'started_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
        'finished_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
    ],
)]
class ReleaseMember extends BaseModel
{
    protected $fillable = [
        'uuid',
        'release_id',
        'release_group_member_id',
        'group_member_uuid',
        'resource_type',
        'resource_id',
        'resource_name',
        'phase',
        'is_required',
        'force_rebuild',
        'depends_on',
        'requested_commit',
        'state',
        'deployment_uuid',
        'deployment_status',
        'actual_commit',
        'image_digest',
        'verification',
        'failure_reason',
        'note',
        'attempts',
        'queued_at',
        'started_at',
        'deployed_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => ReleaseMemberState::class,
            'resource_id' => 'integer',
            'phase' => 'integer',
            'is_required' => 'boolean',
            'force_rebuild' => 'boolean',
            'depends_on' => 'array',
            'verification' => 'array',
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'deployed_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Lets the releases that wait for this deployment continue right away instead of on the next reconcile.
     */
    public static function wakeReleasesFor(string $deploymentUuid): void
    {
        self::query()
            ->where('deployment_uuid', $deploymentUuid)
            ->whereIn('state', ReleaseMemberState::inFlightValues())
            ->distinct()
            ->pluck('release_id')
            ->each(fn (int $releaseId) => ProcessReleaseJob::dispatch($releaseId)->afterCommit());
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    public function resource(): MorphTo
    {
        return $this->morphTo();
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(ApplicationDeploymentQueue::class, 'deployment_uuid', 'deployment_uuid');
    }

    /**
     * @return list<string>
     */
    public function dependencies(): array
    {
        return array_values(array_filter((array) $this->depends_on, 'is_string'));
    }
}
