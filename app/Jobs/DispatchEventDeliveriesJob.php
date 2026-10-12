<?php

namespace App\Jobs;

use App\Models\EventDelivery;
use App\Models\PlatformEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Hands due deliveries to workers again (retries and deliveries whose job was lost) and removes old events.
 */
class DispatchEventDeliveriesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 1;

    public int $uniqueFor = 55;

    public function handle(): void
    {
        EventDelivery::query()
            ->where('state', EventDelivery::PENDING)
            ->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')
            ->limit(200)
            ->pluck('id')
            ->each(fn (int $id) => DeliverEventJob::dispatch($id));

        PlatformEvent::query()->where('occurred_at', '<', now()->subDays(PlatformEvent::RETENTION_DAYS))->limit(1000)->delete();
    }
}
