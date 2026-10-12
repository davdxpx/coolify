<?php

namespace App\Services\Configuration;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\EnvironmentVariable;
use App\Models\Service;
use App\Models\SharedEnvironmentVariable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which resources use which shared variables. Values are decrypted only in memory to find
 * `{{scope.KEY}}` references; the graph itself contains keys, names and timestamps, never values.
 */
class SharedVariableGraph
{
    public const REFERENCE_PATTERN = '/{{\s*(team|project|environment|server)\.([A-Za-z0-9_.\-]+)\s*}}/';

    /** @var array<string, SharedEnvironmentVariable|null> */
    private array $sharedCache = [];

    /** @var array<int, Carbon|null> */
    private array $deployedAtCache = [];

    public function __construct(private readonly int $teamId) {}

    /**
     * @return list<array{type: string, key: string}>
     */
    public static function referencesIn(?string $value): array
    {
        if ($value === null || ! str_contains($value, '{{')) {
            return [];
        }
        preg_match_all(self::REFERENCE_PATTERN, $value, $matches, PREG_SET_ORDER);

        return array_values(array_unique(array_map(fn (array $match) => ['type' => $match[1], 'key' => $match[2]], $matches), SORT_REGULAR));
    }

    /**
     * Every reference from resource variables of the team to shared variables.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function edges(?int $environmentId = null): Collection
    {
        $scope = fn ($query) => $query
            ->whereRelation('environment.project', 'team_id', $this->teamId)
            ->when($environmentId !== null, fn ($query) => $query->where('environment_id', $environmentId));
        $applicationIds = $scope(Application::query())->pluck('id');
        $serviceIds = $scope(Service::query())->pluck('id');

        $query = EnvironmentVariable::query()
            ->where('is_preview', false)
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('resourceable_type', Application::class)->whereIn('resourceable_id', $applicationIds))
                ->orWhere(fn ($query) => $query->where('resourceable_type', Service::class)->whereIn('resourceable_id', $serviceIds)))
            ->with('resourceable');

        $edges = collect();
        foreach ($query->lazyById(500) as $variable) {
            $resource = $variable->resourceable;
            if (! $resource instanceof Model) {
                continue;
            }
            foreach (self::referencesIn($variable->value) as $reference) {
                $shared = $this->resolve($reference['type'], $reference['key'], $resource);
                $deployedAt = $resource instanceof Application ? $this->lastDeployedAt($resource) : null;
                $edges->push([
                    'shared_type' => $reference['type'],
                    'shared_key' => $reference['key'],
                    'shared_id' => $shared?->id,
                    'shared_updated_at' => $shared?->updated_at,
                    'resource_type' => $resource instanceof Application ? 'application' : 'service',
                    'resource_uuid' => $resource->uuid,
                    'resource_name' => $resource->name,
                    'environment_id' => (int) $resource->environment_id,
                    'variable_key' => $variable->key,
                    'missing' => $shared === null,
                    'last_deployed_at' => $deployedAt,
                    'stale' => $shared !== null && $deployedAt !== null && $shared->updated_at !== null && $shared->updated_at->greaterThan($deployedAt),
                ]);
            }
        }

        return $edges;
    }

    /**
     * Resources affected when a shared variable changes, and whether they still run an older value.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function impactOf(SharedEnvironmentVariable $shared): Collection
    {
        return $this->edges()->filter(fn (array $edge) => $edge['shared_id'] === $shared->id)->values();
    }

    private function resolve(string $type, string $key, Model $resource): ?SharedEnvironmentVariable
    {
        $scopeId = match ($type) {
            'team' => $this->teamId,
            'project' => $resource->environment?->project_id,
            'environment' => $resource->environment_id,
            'server' => $resource instanceof Application ? $resource->destination?->server_id : $resource->server?->id,
        };
        if ($scopeId === null) {
            return null;
        }
        $cacheKey = "{$type}:{$scopeId}:{$key}";
        if (! array_key_exists($cacheKey, $this->sharedCache)) {
            $this->sharedCache[$cacheKey] = SharedEnvironmentVariable::query()
                ->where('team_id', $this->teamId)
                ->where('type', $type)
                ->where('key', $key)
                ->where("{$type}_id", $scopeId)
                ->first(['id', 'key', 'type', 'team_id', 'project_id', 'environment_id', 'server_id', 'updated_at']);
        }

        return $this->sharedCache[$cacheKey];
    }

    private function lastDeployedAt(Application $application): ?Carbon
    {
        if (! array_key_exists($application->id, $this->deployedAtCache)) {
            $deployment = ApplicationDeploymentQueue::query()
                ->where('application_id', (string) $application->id)
                ->where('pull_request_id', 0)
                ->where('status', ApplicationDeploymentStatus::FINISHED->value)
                ->latest('id')
                ->first(['created_at', 'started_at', 'finished_at']);
            // The value is read when the deployment starts, so the start is what counts.
            $this->deployedAtCache[$application->id] = $deployment ? ($deployment->started_at ?? $deployment->created_at) : null;
        }

        return $this->deployedAtCache[$application->id];
    }
}
