<?php

const SCHEMA_VERSION = 1;
const CHANNELS = ['stable', 'staging', 'development'];
const MAX_RELEASES = 100;

function fail(string $message): never
{
    fwrite(STDERR, "manifest: {$message}\n");
    exit(1);
}

/**
 * @return array<string, string>
 */
function options(array $argv): array
{
    $options = [];
    for ($i = 0; $i < count($argv); $i++) {
        if (! str_starts_with($argv[$i], '--')) {
            continue;
        }
        $key = substr($argv[$i], 2);
        $value = $argv[$i + 1] ?? null;
        if ($value === null || str_starts_with($value, '--')) {
            $options[$key] = '1';
        } else {
            $options[$key] = $value;
            $i++;
        }
    }

    return $options;
}

function required(array $options, string $key, string $pattern): string
{
    $value = $options[$key] ?? '';
    if (preg_match($pattern, $value) !== 1) {
        fail("--{$key} is missing or invalid.");
    }

    return $value;
}

function load(string $path): array
{
    if (! is_file($path)) {
        fail("{$path} does not exist. Run init first.");
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (! is_array($data) || ($data['schema_version'] ?? null) !== SCHEMA_VERSION) {
        fail("{$path} is not a version ".SCHEMA_VERSION.' manifest.');
    }

    return $data;
}

function save(string $path, array $data): void
{
    usort($data['releases'], fn (array $a, array $b) => $b['build'] <=> $a['build']);
    $referenced = array_values($data['channels']);
    $kept = [];
    foreach ($data['releases'] as $release) {
        if (count($kept) < MAX_RELEASES || in_array($release['build'], $referenced, true)) {
            $kept[] = $release;
        }
    }
    $data['releases'] = $kept;
    $data['generated_at'] = gmdate('Y-m-d\TH:i:s\Z');
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}

function &findRelease(array &$data, int $build): ?array
{
    foreach ($data['releases'] as &$release) {
        if ($release['build'] === $build) {
            return $release;
        }
    }
    $missing = null;

    return $missing;
}

$command = $argv[1] ?? '';
$path = $argv[2] ?? '';
$options = options(array_slice($argv, 3));
if ($path === '') {
    fail('Usage: manifest.php <init|add-release|promote|set-upstream|get> <manifest.json> [options]');
}

$officialVersion = '/^\d+\.\d+(?:\.\d+)?(?:-(?:alpha|beta|rc)\.\d+(?:\.\d+)*)?$/';
$distributionPattern = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}\/[A-Za-z0-9._-]{1,100}$/';
$imagePattern = '/^[a-z0-9]+(?:[.-][a-z0-9]+)*(?::\d{1,5})?(?:\/[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*)+$/';

switch ($command) {
    case 'init':
        if (is_file($path)) {
            exit(0);
        }
        save($path, [
            'schema_version' => SCHEMA_VERSION,
            'distribution' => required($options, 'distribution', $distributionPattern),
            'image' => required($options, 'image', $imagePattern),
            'channels' => [],
            'releases' => [],
        ]);
        break;

    case 'add-release':
        $data = load($path);
        $build = (int) required($options, 'build', '/^[1-9]\d{0,9}$/');
        $image = required($options, 'image', $imagePattern);
        if ($image !== $data['image']) {
            fail("Image {$image} does not belong to this distribution ({$data['image']}).");
        }
        $digest = required($options, 'digest', '/^sha256:[0-9a-f]{64}$/');
        $channel = required($options, 'channel', '/^(stable|staging|development)$/');
        $checks = [];
        foreach (array_filter(explode(',', $options['checks'] ?? '')) as $pair) {
            [$name, $conclusion] = array_pad(explode('=', $pair, 2), 2, '');
            if (preg_match('/^[A-Za-z0-9 _.-]{1,120}$/', $name) !== 1 || preg_match('/^[a-z_]{1,40}$/', $conclusion) !== 1) {
                fail("Invalid check {$pair}.");
            }
            $checks[] = ['name' => $name, 'conclusion' => $conclusion];
        }
        if ($checks === []) {
            fail('At least one verification check is required.');
        }
        $passed = ! in_array(false, array_map(fn ($check) => $check['conclusion'] === 'success', $checks), true);
        $existing = &findRelease($data, $build);
        if ($existing !== null) {
            if ($existing['digest'] !== $digest || $existing['commit'] !== ($options['commit'] ?? '')) {
                fail("Build {$build} is already published with another digest or commit.");
            }
            exit(0);
        }
        $latestMigration = $options['latest-migration'] ?? '';
        $data['releases'][] = [
            'version' => required($options, 'version', '/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}$/'),
            'base_version' => required($options, 'base-version', $officialVersion),
            'build' => $build,
            'commit' => required($options, 'commit', '/^[0-9a-f]{40}$/'),
            'image' => $image,
            'digest' => $digest,
            'channels' => $passed ? [$channel] : [],
            'verification' => [
                'status' => $passed ? 'passed' : 'failed',
                'checks' => $checks,
                'url' => isset($options['verification-url']) && str_starts_with($options['verification-url'], 'https://') ? $options['verification-url'] : null,
            ],
            'migrations' => [
                'latest' => preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]{1,200}$/', $latestMigration) === 1 ? $latestMigration : null,
                'count' => ctype_digit($options['migration-count'] ?? '') ? (int) $options['migration-count'] : null,
            ],
            'published_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'notes_url' => isset($options['notes-url']) && str_starts_with($options['notes-url'], 'https://') ? $options['notes-url'] : null,
            'summary' => isset($options['summary']) ? mb_substr($options['summary'], 0, 500) : null,
        ];
        if ($passed) {
            $data['channels'][$channel] = $build;
        }
        save($path, $data);
        if (! $passed) {
            fail("Build {$build} was recorded as failed and was not published on {$channel}.");
        }
        break;

    case 'promote':
        $data = load($path);
        $build = (int) required($options, 'build', '/^[1-9]\d{0,9}$/');
        $channel = required($options, 'channel', '/^(stable|staging)$/');
        $release = &findRelease($data, $build);
        if ($release === null) {
            fail("Build {$build} is not in the manifest.");
        }
        if (($release['verification']['status'] ?? null) !== 'passed') {
            fail("Build {$build} has not passed verification.");
        }
        if ($channel === 'stable' && ! in_array('staging', $release['channels'], true) && ! isset($options['allow-without-staging'])) {
            fail("Build {$build} was never published on staging.");
        }
        $current = $data['channels'][$channel] ?? null;
        if ($current !== null && $current > $build && ! isset($options['allow-older'])) {
            fail("The {$channel} channel already points to the newer build {$current}.");
        }
        if (isset($options['version'])) {
            $release['version'] = required($options, 'version', '/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}$/');
        }
        if (isset($options['notes-url']) && str_starts_with($options['notes-url'], 'https://')) {
            $release['notes_url'] = $options['notes-url'];
        }
        if (isset($options['summary'])) {
            $release['summary'] = mb_substr($options['summary'], 0, 500);
        }
        $release['channels'] = array_values(array_unique([...$release['channels'], $channel]));
        $data['channels'][$channel] = $build;
        save($path, $data);
        break;

    case 'set-upstream':
        $data = load($path);
        $upstream = json_decode((string) file_get_contents(required($options, 'json', '/^.+$/')), true);
        if (! is_array($upstream)) {
            fail('The upstream status is not valid JSON.');
        }
        $data['upstream'] = $upstream;
        save($path, $data);
        break;

    case 'get':
        $data = load($path);
        $build = (int) required($options, 'build', '/^[1-9]\d{0,9}$/');
        $release = &findRelease($data, $build);
        if ($release === null) {
            fail("Build {$build} is not in the manifest.");
        }
        $field = required($options, 'field', '/^(version|base_version|commit|image|digest|channels)$/');
        echo is_array($release[$field]) ? implode(',', $release[$field]) : $release[$field], "\n";
        break;

    default:
        fail("Unknown command {$command}.");
}
