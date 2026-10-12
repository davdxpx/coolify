<?php

use App\Services\Distribution\BuildIdentity;
use App\Services\Distribution\DistributionCheck;
use App\Services\Distribution\DistributionManifest;
use App\Services\Distribution\DistributionUpdateState;
use App\Services\Distribution\InvalidDistributionManifest;
use App\Services\Distribution\OfficialVersion;
use App\Services\Distribution\RunningImage;

it('treats an image without a build identity file as the official distribution', function () {
    $identity = BuildIdentity::fromFile(sys_get_temp_dir().'/missing-'.uniqid().'.json');

    expect($identity->isCustom())->toBeFalse()
        ->and($identity->isValid())->toBeTrue();
});

it('fails closed when a build identity file exists but is invalid', function () {
    $path = tempnam(sys_get_temp_dir(), 'build');
    file_put_contents($path, '{"schema_version":1,"type":"custom","source_commit":"abc"}');

    $identity = BuildIdentity::fromFile($path);
    unlink($path);

    expect($identity->isCustom())->toBeTrue()
        ->and($identity->isValid())->toBeFalse();
});

it('rejects identities with insecure or incomplete distribution sources', function (array $overrides) {
    expect(distributionTestIdentity($overrides)->isValid())->toBeFalse();
})->with([
    'plain http manifest' => [['manifest_url' => 'http://manifest.test/manifest.json']],
    'image without registry host' => [['image_repository' => 'acme/coolify']],
    'uppercase image' => [['image_repository' => 'ghcr.io/Acme/coolify']],
    'short commit' => [['source_commit' => 'abc123']],
    'fork label as base version' => [['base_version' => '4.4.6-fork.1']],
    'missing build number' => [['build_number' => 0]],
    'half signature policy' => [['signature' => ['issuer' => 'https://token.actions.githubusercontent.com']]],
]);

it('accepts registry hosts with ports and localhost', function (string $repository) {
    expect(distributionTestIdentity(['image_repository' => $repository])->isValid())->toBeTrue();
})->with(['ghcr.io/acme/coolify', 'registry.example.com:5000/acme/coolify', 'localhost:5000/acme/coolify', 'localhost/acme/coolify']);

it('accepts a complete custom identity with a signature policy', function () {
    $identity = distributionTestIdentity(['signature' => [
        'issuer' => 'https://token.actions.githubusercontent.com',
        'identity_regexp' => '^https://github\.com/acme/coolify/\.github/workflows/distribution-build\.yml@refs/heads/main$',
    ]]);

    expect($identity->isValid())->toBeTrue()
        ->and($identity->requiresSignature())->toBeTrue()
        ->and($identity->environmentVersionMismatch('4.4.7'))->toBeTrue()
        ->and($identity->environmentVersionMismatch('4.4.6'))->toBeFalse();
});

it('compares only official version formats', function () {
    expect(OfficialVersion::compare('4.4.6', '4.4.7'))->toBe(-1)
        ->and(OfficialVersion::compare('4.5-rc.1', '4.5.0'))->toBe(-1)
        ->and(OfficialVersion::isValid('4.4.6-fork.3'))->toBeFalse()
        ->and(OfficialVersion::isValid('4.4.6-dev.40'))->toBeFalse();

    expect(fn () => OfficialVersion::compare('4.4.6-fork.3', '4.4.6'))->toThrow(InvalidArgumentException::class);
});

it('parses releases and channel pointers of a valid manifest', function () {
    $manifest = DistributionManifest::fromArray(distributionTestManifest(
        [distributionTestRelease(41), distributionTestRelease(42, ['channels' => ['staging']])],
        ['stable' => 41, 'staging' => 42],
    ), distributionTestIdentity());

    expect($manifest->releaseForChannel('stable')->build)->toBe(41)
        ->and($manifest->releaseForChannel('staging')->build)->toBe(42)
        ->and($manifest->releaseForChannel('development'))->toBeNull()
        ->and($manifest->releases[0]->build)->toBe(42);
});

it('rejects manifests that point outside the trusted distribution', function (Closure $mutate, string $message) {
    $data = distributionTestManifest([distributionTestRelease(41)], ['stable' => 41]);

    expect(fn () => DistributionManifest::fromArray($mutate($data), distributionTestIdentity()))
        ->toThrow(InvalidDistributionManifest::class, $message);
})->with([
    'other distribution' => [fn (array $data) => [...$data, 'distribution' => 'evil/coolify'], 'another distribution'],
    'other manifest image' => [fn (array $data) => [...$data, 'image' => 'ghcr.io/evil/coolify'], 'untrusted image repository'],
    'release image elsewhere' => [function (array $data) {
        $data['releases'][0]['image'] = 'docker.io/evil/coolify';

        return $data;
    }, 'outside the trusted repository'],
    'tag instead of digest' => [function (array $data) {
        $data['releases'][0]['digest'] = 'latest';

        return $data;
    }, 'invalid image digest'],
    'unknown channel build' => [fn (array $data) => [...$data, 'channels' => ['stable' => 99]], 'unknown build'],
    'duplicate build' => [fn (array $data) => [...$data, 'releases' => [distributionTestRelease(41), distributionTestRelease(41)]], 'twice'],
    'unsupported schema' => [fn (array $data) => [...$data, 'schema_version' => 2], 'schema version'],
]);

it('does not accept a manifest for an official installation', function () {
    expect(fn () => DistributionManifest::fromArray(distributionTestManifest([], []), BuildIdentity::official()))
        ->toThrow(InvalidDistributionManifest::class);
});

it('offers a verified newer fork release on the selected channel', function () {
    $manifest = DistributionManifest::fromArray(distributionTestManifest(
        [distributionTestRelease(40, ['commit' => str_repeat('a', 40)]), distributionTestRelease(41)],
        ['stable' => 41],
    ), distributionTestIdentity());

    $state = DistributionUpdateState::resolve(distributionTestIdentity(), new DistributionCheck($manifest, now()->toIso8601String(), null), 'stable');

    expect($state->updateAvailable)->toBeTrue()
        ->and($state->candidate->build)->toBe(41)
        ->and($state->running->build)->toBe(40)
        ->and($state->runningLabel())->toBe('4.4.6-fork.40');
});

it('does not mark a release that failed CI as installable', function () {
    $manifest = DistributionManifest::fromArray(distributionTestManifest(
        [distributionTestRelease(41, ['verification' => ['status' => 'failed', 'checks' => [['name' => 'tests', 'conclusion' => 'failure']]]])],
        ['stable' => 41],
    ), distributionTestIdentity());

    $state = DistributionUpdateState::resolve(distributionTestIdentity(), new DistributionCheck($manifest, now()->toIso8601String(), null), 'stable');

    expect($state->updateAvailable)->toBeFalse()
        ->and($state->blockedReason)->toContain('has not passed CI verification');
});

it('downgrades a passed verification that lists a failing check', function () {
    $manifest = DistributionManifest::fromArray(distributionTestManifest(
        [distributionTestRelease(41, ['verification' => ['checks' => [['name' => 'tests', 'conclusion' => 'success'], ['name' => 'upgrade-smoke', 'conclusion' => 'failure']]]])],
        ['stable' => 41],
    ), distributionTestIdentity());

    expect($manifest->findByBuild(41)->isVerified())->toBeFalse();
});

it('does not treat a newer build with an older official base as a fork update', function () {
    $manifest = DistributionManifest::fromArray(distributionTestManifest(
        [distributionTestRelease(41, ['base_version' => '4.4.5'])],
        ['stable' => 41],
    ), distributionTestIdentity());

    $state = DistributionUpdateState::resolve(distributionTestIdentity(), new DistributionCheck($manifest, now()->toIso8601String(), null), 'stable');

    expect($state->updateAvailable)->toBeFalse()
        ->and($state->blockedReason)->toContain('older than the running base');
});

it('does not offer the running or an older build as an update', function () {
    $manifest = DistributionManifest::fromArray(distributionTestManifest(
        [distributionTestRelease(39), distributionTestRelease(40, ['commit' => str_repeat('a', 40)])],
        ['stable' => 39, 'staging' => 40],
    ), distributionTestIdentity());

    foreach (['stable', 'staging'] as $channel) {
        $state = DistributionUpdateState::resolve(distributionTestIdentity(), new DistributionCheck($manifest, now()->toIso8601String(), null), $channel);
        expect($state->updateAvailable)->toBeFalse();
    }
});

it('blocks updates when the manifest lists another commit for the running build', function () {
    $manifest = DistributionManifest::fromArray(distributionTestManifest(
        [distributionTestRelease(40, ['commit' => str_repeat('f', 40)]), distributionTestRelease(41)],
        ['stable' => 41],
    ), distributionTestIdentity());

    $state = DistributionUpdateState::resolve(distributionTestIdentity(), new DistributionCheck($manifest, now()->toIso8601String(), null), 'stable');

    expect($state->manifestDisagreesWithBuild)->toBeTrue()
        ->and($state->updateAvailable)->toBeFalse();
});

it('reports upstream releases and advisories separately from fork releases', function () {
    $manifest = DistributionManifest::fromArray(distributionTestManifest(
        [distributionTestRelease(41)],
        ['stable' => 41],
        ['upstream' => [
            'repository' => 'coollabsio/coolify',
            'latest_release' => '4.4.8',
            'base_version' => '4.4.6',
            'commits_behind' => 31,
            'sync' => ['state' => 'conflicts', 'conflicting_files' => ['app/Livewire/Upgrade.php']],
            'advisories' => [
                ['id' => 'GHSA-aaaa', 'severity' => 'high', 'summary' => 'Fixed later', 'first_patched_version' => '4.4.7'],
                ['id' => 'GHSA-bbbb', 'severity' => 'low', 'summary' => 'Already fixed', 'first_patched_version' => '4.4.5'],
            ],
        ]],
    ), distributionTestIdentity());

    expect($manifest->upstream->isBehind('4.4.6'))->toBeTrue()
        ->and($manifest->upstream->syncState)->toBe('conflicts')
        ->and($manifest->upstream->conflictingFiles)->toBe(['app/Livewire/Upgrade.php'])
        ->and(array_column($manifest->upstream->advisoriesPendingFor('4.4.6'), 'id'))->toBe(['GHSA-aaaa']);
});

it('reads the running image digest from docker inspect output', function () {
    $digest = 'sha256:'.str_repeat('e', 64);
    $image = RunningImage::parse("sha256:".str_repeat('1', 64)."|ghcr.io/acme/coolify@{$digest}\n[\"ghcr.io/acme/coolify@{$digest}\",\"docker.io/library/redis@sha256:".str_repeat('2', 64)."\"]");

    expect($image)->not->toBeNull()
        ->and($image->digestFor('ghcr.io/acme/coolify'))->toBe($digest)
        ->and($image->runs('ghcr.io/acme/coolify', $digest))->toBeTrue()
        ->and($image->runs('ghcr.io/other/coolify', $digest))->toBeFalse()
        ->and($image->digestFor('redis'))->toBe('sha256:'.str_repeat('2', 64))
        ->and(RunningImage::parse('Error: No such object: coolify'))->toBeNull();
});
