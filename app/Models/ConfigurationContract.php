<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'The environment variable keys an application needs. Only keys are stored, never values.',
    type: 'object',
    properties: [
        'required_keys' => ['type' => 'array', 'items' => ['type' => 'string']],
        'optional_keys' => ['type' => 'array', 'items' => ['type' => 'string']],
        'source' => ['type' => 'string', 'nullable' => true, 'description' => 'Where the contract comes from, for example a file in a repository.'],
        'source_revision' => ['type' => 'string', 'nullable' => true],
        'updated_at' => ['type' => 'string', 'format' => 'date-time'],
    ],
)]
class ConfigurationContract extends BaseModel
{
    public const MAX_KEYS = 500;

    protected $fillable = [
        'uuid',
        'team_id',
        'application_id',
        'required_keys',
        'optional_keys',
        'source',
        'source_revision',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'required_keys' => 'array',
            'optional_keys' => 'array',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return list<string>
     */
    public function requiredKeys(): array
    {
        return array_values(array_unique(array_filter((array) $this->required_keys, 'is_string')));
    }

    /**
     * @return list<string>
     */
    public function optionalKeys(): array
    {
        return array_values(array_unique(array_filter((array) $this->optional_keys, 'is_string')));
    }
}
