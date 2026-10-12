<?php

namespace App\Services\Platform;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Services\Configuration\SharedVariableGraph;
use App\Services\DeploymentConfiguration\ConfigurationDiffer;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Earlier successful deployments an application can return to, and what a rollback would and
 * would not restore. Coolify's rollback starts the old image with the current configuration.
 */
class RollbackAdvisor
{
    /**
     * @param  list<string>|null  $localImageTags  tags of `<image>:<tag>` present on the server, or null when not checked
     * @return array<string, mixed>
     */
    public function candidates(Application $application, int $limit = 10, ?array $localImageTags = null): array
    {
        $deployments = ApplicationDeploymentQueue::query()
            ->where('application_id', (string) $application->id)
            ->where('pull_request_id', 0)
            ->where('status', ApplicationDeploymentStatus::FINISHED->value)
            ->whereNull('parent_deployment_uuid')
            ->latest('id')
            ->limit(100)
            ->get();
        $current = $deployments->first();
        $edges = (new SharedVariableGraph((int) $application->team()?->id))->edges((int) $application->environment_id)->where('resource_uuid', $application->uuid);
        $currentSnapshot = rescue(fn () => $application->deploymentConfigurationSnapshot(), null, false);

        $candidates = $deployments->skip(1)
            ->unique('commit')
            ->reject(fn (ApplicationDeploymentQueue $deployment) => $current !== null && $deployment->commit === $current->commit)
            ->take(max(1, min(25, $limit)))
            ->map(fn (ApplicationDeploymentQueue $deployment) => $this->describe($application, $deployment, $currentSnapshot, $edges, $localImageTags))
            ->values();

        return [
            'application_uuid' => $application->uuid,
            'build_pack' => $application->build_pack,
            'current' => $current ? [
                'deployment_uuid' => $current->deployment_uuid,
                'commit' => $current->commit,
                'finished_at' => ($current->finished_at ?? $current->updated_at)?->toIso8601String(),
            ] : null,
            'image_rollback_supported' => $application->build_pack !== 'dockercompose',
            'images_checked' => $localImageTags !== null,
            'candidates' => $candidates->all(),
            'notes' => array_values(array_filter([
                'A rollback starts the earlier image with the current environment variables and settings.',
                'Database changes made by later versions are not reverted by a rollback.',
                $application->build_pack === 'dockercompose' ? 'Docker Compose applications have no image rollback; deploy the earlier commit instead.' : null,
            ])),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $edges
     * @param  list<string>|null  $localImageTags
     * @return array<string, mixed>
     */
    private function describe(Application $application, ApplicationDeploymentQueue $deployment, ?array $currentSnapshot, $edges, ?array $localImageTags): array
    {
        $startedAt = $deployment->started_at ?? $deployment->created_at;
        $changes = null;
        if ($currentSnapshot !== null && is_array($deployment->configuration_snapshot)) {
            try {
                $diff = app(ConfigurationDiffer::class)->diff($deployment->configuration_snapshot, $currentSnapshot);
                $changes = [
                    'count' => $diff->count(),
                    'requires_build' => $diff->requiresBuild(),
                    'items' => collect($diff->changes())->map(fn (array $change) => $change['label'] ?? $change['key'] ?? null)->filter()->take(20)->values()->all(),
                ];
            } catch (Throwable) {
                $changes = null;
            }
        }
        $sharedChanged = $edges->filter(fn (array $edge) => $edge['shared_updated_at'] !== null && $startedAt !== null && $edge['shared_updated_at']->greaterThan($startedAt))
            ->map(fn (array $edge) => "{$edge['shared_type']}.{$edge['shared_key']}")->unique()->values()->all();
        $imageAvailable = $localImageTags === null ? null : in_array((string) $deployment->commit, $localImageTags, true);

        $warnings = [];
        if ($changes !== null && $changes['count'] > 0) {
            $warnings[] = "{$changes['count']} configuration changes since this deployment stay in place.";
        }
        if ($sharedChanged !== []) {
            $warnings[] = 'Shared variables changed since: '.implode(', ', $sharedChanged).'.';
        }
        if ($imageAvailable === false) {
            $warnings[] = 'The image of this commit is no longer on the server; a rollback rebuilds it.';
        }

        return [
            'deployment_uuid' => $deployment->deployment_uuid,
            'commit' => $deployment->commit,
            'commit_message' => $deployment->commit_message,
            'finished_at' => ($deployment->finished_at ?? $deployment->updated_at)?->toIso8601String(),
            'images' => (array) data_get($deployment->artifacts, 'images', []),
            'image_available' => $imageAvailable,
            'configuration_changes_since' => $changes,
            'shared_variables_changed_since' => $sharedChanged,
            'warnings' => $warnings,
        ];
    }

    /**
     * Tags of the application's local images on its server.
     *
     * @return list<string>
     */
    public function localImageTags(Application $application): array
    {
        $server = $application->destination?->server;
        if ($server === null || ! $server->isFunctional()) {
            return [];
        }
        $image = $application->docker_registry_image_name ?: $application->uuid;
        $output = (string) instant_remote_process(['docker images --format '.escapeshellarg('{{.Repository}}#{{.Tag}}').' '.escapeshellarg($image)], $server, false);

        return collect(preg_split('/\R/', trim($output)) ?: [])
            ->map(fn (string $line) => explode('#', $line)[1] ?? null)
            ->filter(fn (?string $tag) => filled($tag) && $tag !== '<none>')
            ->values()
            ->all();
    }
}
