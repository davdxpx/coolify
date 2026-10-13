<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ReleaseGroupMember extends BaseModel
{
    protected $fillable = [
        'uuid',
        'release_group_id',
        'resource_type',
        'resource_id',
        'role',
        'phase',
        'position',
        'is_required',
        'is_enabled',
        'force_rebuild',
        'depends_on',
    ];

    protected function casts(): array
    {
        return [
            'resource_id' => 'integer',
            'phase' => 'integer',
            'position' => 'integer',
            'is_required' => 'boolean',
            'is_enabled' => 'boolean',
            'force_rebuild' => 'boolean',
            'depends_on' => 'array',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ReleaseGroup::class, 'release_group_id');
    }

    public function resource(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return list<string>
     */
    public function dependencies(): array
    {
        return array_values(array_filter((array) $this->depends_on, 'is_string'));
    }
}
