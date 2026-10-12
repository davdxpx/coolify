<?php

namespace App\Jobs;

use App\Models\Release;
use App\Services\Release\ReleaseOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ProcessReleaseJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 1;

    public $timeout = 120;

    public int $uniqueFor = 60;

    public function __construct(public int $releaseId)
    {
        $this->onQueue('high');
    }

    public function uniqueId(): string
    {
        return (string) $this->releaseId;
    }

    public function handle(ReleaseOrchestrator $orchestrator): void
    {
        $release = Release::query()->find($this->releaseId);
        if ($release !== null) {
            $orchestrator->tick($release);
        }
    }
}
