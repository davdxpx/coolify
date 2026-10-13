<?php

namespace App\Services\Deployment;

use App\Models\Application;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Zero-downtime deployments for Docker Compose applications: the new version starts as a second
 * Compose project next to the running one, the proxy serves both while the new one becomes
 * healthy, then the old project is stopped. This class decides whether that is safe and plans it.
 */
class ComposeBlueGreen
{
    public const WAIT_SECONDS = 300;

    public const GREEN_SUFFIX = '-green';

    /** Images whose data must never be written by two containers at once. */
    private const STATEFUL_IMAGES = [
        'postgres', 'postgresql', 'postgis', 'timescaledb', 'mysql', 'mysql-server', 'mariadb', 'percona', 'percona-server',
        'mongo', 'mongodb', 'redis', 'redis-stack', 'redis-stack-server', 'valkey', 'keydb', 'dragonfly', 'clickhouse',
        'clickhouse-server', 'elasticsearch', 'opensearch', 'rabbitmq', 'minio', 'influxdb', 'cockroach', 'etcd',
        'zookeeper', 'kafka', 'cassandra', 'scylla', 'neo4j', 'couchdb', 'meilisearch', 'typesense', 'qdrant',
        'weaviate', 'milvus', 'mssql-server',
    ];

    /**
     * @return array{eligible: bool, blocking: list<string>, warnings: list<string>, services: list<string>}
     */
    public function analyze(Application $application): array
    {
        $blocking = [];
        $warnings = [];

        if ($application->build_pack !== 'dockercompose') {
            return ['eligible' => false, 'blocking' => ['Only Docker Compose applications support this deployment mode.'], 'warnings' => [], 'services' => []];
        }
        if ($application->settings?->is_raw_compose_deployment_enabled) {
            $blocking[] = 'Raw compose deployments are deployed as written and cannot run twice side by side.';
        }
        if (filled($application->docker_compose_custom_start_command)) {
            $blocking[] = 'A custom start command decides itself how containers start.';
        }
        if ($application->destination?->server?->isSwarm()) {
            $blocking[] = 'Swarm servers are not supported.';
        }
        if ($application->settings?->is_consistent_container_name_enabled) {
            $blocking[] = 'Consistent container names allow only one container per service.';
        }

        try {
            $compose = Yaml::parse((string) ($application->docker_compose_raw ?: $application->docker_compose));
        } catch (Throwable) {
            $compose = null;
        }
        $services = is_array($compose) && is_array($compose['services'] ?? null) ? $compose['services'] : [];
        if ($services === []) {
            $blocking[] = 'The Compose file could not be read.';
        }

        $withoutHealthCheck = [];
        $withVolumes = [];
        foreach ($services as $name => $service) {
            $service = is_array($service) ? $service : [];
            if (! empty($service['ports'])) {
                $blocking[] = "{$name} publishes host ports; two versions cannot bind the same port.";
            }
            if (isset($service['container_name'])) {
                $blocking[] = "{$name} sets container_name; two versions cannot use the same name.";
            }
            if (isset($service['network_mode'])) {
                $blocking[] = "{$name} uses network_mode.";
            }
            if (($image = self::statefulImage((string) ($service['image'] ?? ''))) !== null) {
                $blocking[] = "{$name} runs {$image}, which would run twice on the same data. Move it to a separate resource.";
            }
            if (($service['deploy']['replicas'] ?? 1) > 1) {
                $warnings[] = "{$name} has several replicas; each version starts all of them.";
            }
            if (! empty($service['volumes'])) {
                $withVolumes[] = (string) $name;
            }
            if (! isset($service['healthcheck']) || ($service['healthcheck']['disable'] ?? false)) {
                $withoutHealthCheck[] = (string) $name;
            }
        }

        if ($withVolumes !== []) {
            $warnings[] = 'Both versions use the same volumes while they run side by side: '.implode(', ', $withVolumes).'.';
        }
        if ($withoutHealthCheck !== []) {
            $warnings[] = 'No health check in the Compose file for '.implode(', ', $withoutHealthCheck).'. Coolify then waits for a health check in the image or only until the container runs.';
        }
        if (count($services) > 1) {
            $warnings[] = 'During the switch, service names on the application network resolve to containers of both versions.';
        }

        return [
            'eligible' => $blocking === [],
            'blocking' => array_values(array_unique($blocking)),
            'warnings' => $warnings,
            'services' => array_map('strval', array_keys($services)),
        ];
    }

    public static function statefulImage(string $image): ?string
    {
        $name = strtolower(preg_replace('/@.*$/', '', $image) ?? '');
        $name = preg_replace('/:[^:\/]*$/', '', $name) ?? $name;
        $base = basename($name);

        return in_array($base, self::STATEFUL_IMAGES, true) ? $base : null;
    }

    /**
     * Lists the running containers of the application with their Compose project.
     */
    public static function listContainersCommand(string $uuid): string
    {
        $commands = dockerPsByOwnerCommands('application', $uuid, ['label=coolify.pullRequestId=0'], '{{.Names}}|{{.Label "com.docker.compose.project"}}|{{.Label "com.docker.compose.service"}}', false, []);

        return '{ '.implode('; ', $commands).'; } | sort -u';
    }

    /**
     * @return list<array{name: string, project: string, service: string}>
     */
    public static function parseContainers(string $output): array
    {
        $containers = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $parts = explode('|', trim($line));
            if (count($parts) === 3 && $parts[0] !== '') {
                $containers[$parts[0]] = ['name' => $parts[0], 'project' => $parts[1], 'service' => $parts[2]];
            }
        }

        return array_values($containers);
    }

    /**
     * The project that runs now and the one the new version starts in. Colors alternate between
     * `<uuid>` and `<uuid>-green`.
     *
     * @param  list<array{name: string, project: string, service: string}>  $containers
     * @return array{current: string|null, next: string}
     */
    public static function plan(string $uuid, array $containers): array
    {
        $green = $uuid.self::GREEN_SUFFIX;
        $projects = array_count_values(array_column($containers, 'project'));
        arsort($projects);
        $current = array_key_first($projects);

        return [
            'current' => $current === null ? null : (string) $current,
            'next' => $current === null || $current === $green ? $uuid : $green,
        ];
    }

    public static function removeProjectCommand(string $project): string
    {
        $filter = escapeshellarg("label=com.docker.compose.project={$project}");

        return "docker ps -aq --filter {$filter} | xargs -r docker rm -f";
    }
}
