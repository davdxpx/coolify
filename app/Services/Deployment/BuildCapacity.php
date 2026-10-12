<?php

namespace App\Services\Deployment;

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\DeploymentPhase;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;

/**
 * How busy a server's deployment slots are. A slot is held by every `in_progress` deployment,
 * also before a worker started it, which is why reserved and running slots are reported apart.
 */
final class BuildCapacity
{
    public function __construct(
        public readonly string $serverUuid,
        public readonly string $serverName,
        public readonly int $concurrentBuilds,
        public readonly int $running,
        public readonly int $reserved,
        public readonly int $queued,
        public readonly int $queueLimit,
        public readonly ?int $oldestQueuedSeconds,
    ) {}

    public static function forServer(Server $server): self
    {
        $inProgress = ApplicationDeploymentQueue::query()
            ->where('server_id', $server->id)
            ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->get(['id', 'phase', 'started_at']);
        $waitingPhases = array_map(fn (DeploymentPhase $phase) => $phase->value, array_filter(DeploymentPhase::cases(), fn (DeploymentPhase $phase) => $phase->isWaiting()));
        $reserved = $inProgress->filter(fn (ApplicationDeploymentQueue $deployment) => $deployment->started_at === null && in_array($deployment->phase?->value, $waitingPhases, true))->count();

        $queued = ApplicationDeploymentQueue::query()
            ->where('server_id', $server->id)
            ->where('status', ApplicationDeploymentStatus::QUEUED->value);
        $oldest = (clone $queued)->min('created_at');

        return new self(
            serverUuid: (string) $server->uuid,
            serverName: (string) $server->name,
            concurrentBuilds: max(0, (int) ($server->settings->concurrent_builds ?? 2)),
            running: $inProgress->count() - $reserved,
            reserved: $reserved,
            queued: $queued->count(),
            queueLimit: (int) ($server->settings->deployment_queue_limit ?? 25),
            oldestQueuedSeconds: $oldest ? (int) max(0, now()->diffInSeconds($oldest, true)) : null,
        );
    }

    public function freeSlots(): int
    {
        return max(0, $this->concurrentBuilds - $this->running - $this->reserved);
    }

    public function queueAlmostFull(): bool
    {
        return $this->queued >= max(1, $this->queueLimit - 2);
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'server_uuid' => $this->serverUuid,
            'server_name' => $this->serverName,
            'concurrent_builds' => $this->concurrentBuilds,
            'running' => $this->running,
            'reserved' => $this->reserved,
            'free_slots' => $this->freeSlots(),
            'queued' => $this->queued,
            'queue_limit' => $this->queueLimit,
            'oldest_queued_seconds' => $this->oldestQueuedSeconds,
        ];
    }
}
