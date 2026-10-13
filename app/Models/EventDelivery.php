<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventDelivery extends BaseModel
{
    public const PENDING = 'pending';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    public const MAX_ATTEMPTS = 8;

    /** Seconds to wait after the n-th failed attempt. */
    public const BACKOFF = [30, 120, 600, 1800, 3600, 7200, 14400];

    protected $fillable = ['uuid', 'platform_event_id', 'event_subscription_id', 'state', 'attempts', 'next_attempt_at', 'response_status', 'last_error', 'delivered_at'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(PlatformEvent::class, 'platform_event_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(EventSubscription::class, 'event_subscription_id');
    }
}
