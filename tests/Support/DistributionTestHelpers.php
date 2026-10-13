<?php

use App\Services\Distribution\BuildIdentity;
use App\Services\Distribution\DistributionManifestClient;
use App\Services\Distribution\RunningImage;
use App\Services\Distribution\RunningImageInspector;
use Illuminate\Support\Facades\Cache;

function distributionTestIdentity(array $overrides = []): BuildIdentity
{
    return BuildIdentity::fromArray(array_merge([
        'schema_version' => 1,
        'type' => 'custom',
        'distribution' => 'acme/coolify',
        'base_version' => '4.4.6',
        'build_number' => 40,
        'source_repository' => 'https://github.com/acme/coolify',
        'source_commit' => str_repeat('a', 40),
        'image_repository' => 'ghcr.io/acme/coolify',
        'manifest_url' => 'https://manifest.test/manifest.json',
    ], $overrides));
}

function distributionTestRelease(int $build, array $overrides = []): array
{
    return array_replace_recursive([
        'version' => "4.4.6-fork.{$build}",
        'base_version' => '4.4.6',
        'build' => $build,
        'commit' => str_pad(dechex($build), 40, 'b', STR_PAD_LEFT),
        'image' => 'ghcr.io/acme/coolify',
        'digest' => 'sha256:'.str_pad(dechex($build), 64, 'c', STR_PAD_LEFT),
        'channels' => ['stable'],
        'verification' => [
            'status' => 'passed',
            'checks' => [['name' => 'tests', 'conclusion' => 'success']],
            'url' => 'https://github.com/acme/coolify/actions/runs/1',
        ],
        'migrations' => ['latest' => '2026_10_12_180100_create_coolify_upgrades_table', 'count' => 447],
        'published_at' => '2026-10-12T10:00:00Z',
    ], $overrides);
}

/**
 * @param  list<array<string, mixed>>  $releases
 * @param  array<string, int>  $channels
 */
function distributionTestManifest(array $releases, array $channels, array $extra = []): array
{
    return array_merge([
        'schema_version' => 1,
        'distribution' => 'acme/coolify',
        'image' => 'ghcr.io/acme/coolify',
        'channels' => $channels,
        'releases' => $releases,
        'generated_at' => '2026-10-12T10:00:00Z',
    ], $extra);
}

function distributionTestCacheManifest(array $manifest, ?string $error = null): void
{
    Cache::put(DistributionManifestClient::CACHE_KEY, [
        'distribution' => $manifest['distribution'],
        'manifest' => $manifest,
        'checked_at' => now()->toIso8601String(),
        'error' => $error,
    ]);
}

function distributionTestRunningImage(?string $digest, string $repository = 'ghcr.io/acme/coolify'): void
{
    $image = $digest === null ? null : new RunningImage(
        'sha256:'.str_repeat('d', 64),
        "{$repository}@{$digest}",
        ["{$repository}@{$digest}"],
        now()->toIso8601String(),
    );

    app()->instance(RunningImageInspector::class, new class($image) extends RunningImageInspector
    {
        public function __construct(private ?RunningImage $image) {}

        public function inspect(bool $fresh = false): ?RunningImage
        {
            return $this->image;
        }
    });
}
