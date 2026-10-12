<?php

namespace App\Services\Deployment;

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;

/**
 * Records which images a finished deployment runs: per container the image reference, the local
 * image id and, when the image came from or went to a registry, its repository digest.
 */
class DeploymentArtifacts
{
    public const MAX_ARTIFACTS = 50;

    public function capture(ApplicationDeploymentQueue $deployment, Application $application, Server $server): array
    {
        $script = containerIdsByOwnerScript('application', $application->uuid, ['label=coolify.pullRequestId=0'], false, [])
            .'; for c in $container_ids; do docker inspect --format '
            .escapeshellarg('{{index .Config.Labels "com.docker.compose.service"}}|{{.Name}}|{{.Config.Image}}|{{.Image}}')
            .' "$c" 2>/dev/null; done';
        $containers = self::parseContainers((string) instant_remote_process([$script], $server, false));
        if ($containers === []) {
            return [];
        }

        $imageIds = array_values(array_unique(array_column($containers, 'image_id')));
        $digests = self::parseImages((string) instant_remote_process([
            'docker image inspect --format '.escapeshellarg('{{.Id}}|{{json .RepoDigests}}').' '.implode(' ', array_map('escapeshellarg', $imageIds)).' 2>/dev/null',
        ], $server, false));

        $artifacts = array_map(function (array $container) use ($digests) {
            $container['repo_digest'] = self::matchingDigest($container['image'], $digests[$container['image_id']] ?? []);

            return $container;
        }, $containers);
        $artifacts = array_slice($artifacts, 0, self::MAX_ARTIFACTS);

        $deployment->forceFill(['artifacts' => ['captured_at' => now()->toIso8601String(), 'server' => $server->name, 'images' => $artifacts]])->save();

        return $artifacts;
    }

    /**
     * @return list<array{service: string|null, container: string, image: string, image_id: string}>
     */
    public static function parseContainers(string $output): array
    {
        $containers = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $parts = explode('|', trim($line));
            if (count($parts) !== 4 || ! preg_match('/^sha256:[a-f0-9]{64}$/', $parts[3])) {
                continue;
            }
            $containers[] = [
                'service' => $parts[0] !== '' && $parts[0] !== '<no value>' ? $parts[0] : null,
                'container' => ltrim($parts[1], '/'),
                'image' => $parts[2],
                'image_id' => $parts[3],
            ];
        }

        return $containers;
    }

    /**
     * @return array<string, list<string>>
     */
    public static function parseImages(string $output): array
    {
        $images = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            [$id, $json] = array_pad(explode('|', trim($line), 2), 2, '');
            if (! preg_match('/^sha256:[a-f0-9]{64}$/', $id)) {
                continue;
            }
            $repoDigests = json_decode($json, true);
            $images[$id] = is_array($repoDigests) ? array_values(array_filter($repoDigests, 'is_string')) : [];
        }

        return $images;
    }

    /**
     * @param  list<string>  $repoDigests
     */
    public static function matchingDigest(string $image, array $repoDigests): ?string
    {
        if (preg_match('/@(sha256:[a-f0-9]{64})$/', $image, $matches)) {
            return $matches[1];
        }
        $repository = preg_replace('/:[^:\/]+$/', '', $image);
        foreach ($repoDigests as $repoDigest) {
            [$name, $digest] = array_pad(explode('@', $repoDigest, 2), 2, '');
            if (($name === $repository || str_ends_with($name, '/'.$repository)) && preg_match('/^sha256:[a-f0-9]{64}$/', $digest)) {
                return $digest;
            }
        }

        return null;
    }

    /**
     * The digest that identifies a deployment's artifact: the repository digest of the first image, else its image id.
     */
    public static function primaryDigest(?array $artifacts): ?string
    {
        $first = $artifacts['images'][0] ?? null;

        return is_array($first) ? ($first['repo_digest'] ?? $first['image_id'] ?? null) : null;
    }
}
