<?php

namespace App\Jobs;

use App\Enums\ReleaseMemberState;
use App\Models\Release;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Safety net that advances every release which still has work, also when a deployment
 * changed its status without a model event or a worker was lost.
 */
class ReconcileReleasesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 1;

    public int $uniqueFor = 55;

    public function handle(): void
    {
        Release::query()
            ->where(fn ($query) => $query->active()->orWhereHas('members', fn ($members) => $members->whereIn('state', ReleaseMemberState::inFlightValues())))
            ->orderBy('id')
            ->limit(200)
            ->pluck('id')
            ->each(fn (int $id) => ProcessReleaseJob::dispatch($id));
    }
}
