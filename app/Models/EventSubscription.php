<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class EventSubscription extends BaseModel
{
    public const MAX_PER_TEAM = 20;

    public const DISABLE_AFTER_FAILURES = 50;

    protected $fillable = [
        'uuid',
        'team_id',
        'name',
        'url',
        'secret',
        'event_types',
        'is_enabled',
        'consecutive_failures',
        'disabled_reason',
        'last_delivery_at',
        'last_response_status',
        'created_by_user_id',
    ];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'url' => 'encrypted',
            'secret' => 'encrypted',
            'event_types' => 'array',
            'is_enabled' => 'boolean',
            'consecutive_failures' => 'integer',
            'last_delivery_at' => 'datetime',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(EventDelivery::class);
    }

    public function wants(string $type): bool
    {
        $types = (array) $this->event_types;

        return in_array('*', $types, true) || in_array($type, $types, true) || $type === 'ping';
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'url_host' => parse_url((string) $this->url, PHP_URL_HOST),
            'event_types' => $this->event_types,
            'is_enabled' => $this->is_enabled,
            'disabled_reason' => $this->disabled_reason,
            'consecutive_failures' => $this->consecutive_failures,
            'last_delivery_at' => $this->last_delivery_at?->toIso8601String(),
            'last_response_status' => $this->last_response_status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
