<?php

use App\Actions\Distribution\ReconcileDistributionUpgrades;
use App\Actions\Distribution\StartDistributionUpgrade;
use App\Actions\Server\CleanupDocker;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\CoolifyUpgradeState;
use App\Enums\PreflightState;
use App\Livewire\Upgrade;
use App\Models\ApplicationDeploymentQueue;
use App\Models\CoolifyUpgrade;
use App\Models\InstanceSettings;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Services\Distribution\BuildIdentity;
use App\Services\Distribution\CoolifyDistribution;
use App\Services\Distribution\DistributionRelease;
use App\Services\Distribution\DistributionUpgradePreflight;
use App\Services\Distribution\DistributionUpgradeRefused;
use App\Services\Preflight\PreflightResult;
use App\Services\Registry\ManifestLookup;
use App\Services\Registry\OciRegistryClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

class DistributionTestStartUpgrade extends StartDistributionUpgrade
{
    /** @var list<string> */
    public static array $launched = [];

    protected function launch(CoolifyUpgrade $upgrade, BuildIdentity $identity): void
    {
        self::$launched[] = $upgrade->uuid;
    }
}

beforeEach(function () {
    Cache::flush();
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
    BuildIdentity::setCurrent(distributionTestIdentity());
    DistributionTestStartUpgrade::$launched = [];
    app()->bind(StartDistributionUpgrade::class, DistributionTestStartUpgrade::class);
    distributionTestRunningImage('sha256:'.str_pad(dechex(40), 64, 'c', STR_PAD_LEFT));
});

afterEach(function () {
    BuildIdentity::setCurrent(null);
});

function distributionUpgradeTestRelease(int $build = 41, array $overrides = []): DistributionRelease
{
    return DistributionRelease::fromArray(distributionTestRelease($build, $overrides), 'ghcr.io/acme/coolify');
}

/**
 * @param  list<PreflightResult>  $results
 */
function distributionUpgradeTestPreflight(array $results): void
{
    $preflight = Mockery::mock(DistributionUpgradePreflight::class);
    $preflight->shouldReceive('run')->andReturn($results);
    app()->instance(DistributionUpgradePreflight::class, $preflight);
}

function distributionUpgradeTestPassing(): array
{
    return [
        new PreflightResult('build_identity', 'Running build', PreflightState::PASSED, 'ok'),
        new PreflightResult('schema_compatibility', 'Database schema', PreflightState::PASSED, 'migrations', details: ['includes_migrations' => true]),
    ];
}

function distributionUpgradeTestRunning(array $overrides = []): CoolifyUpgrade
{
    return CoolifyUpgrade::create(array_merge([
        'distribution' => 'acme/coolify',
        'channel' => 'stable',
        'trigger' => CoolifyUpgrade::TRIGGER_MANUAL,
        'state' => CoolifyUpgradeState::RUNNING,
        'from_version' => '4.4.6-fork.40',
        'from_base_version' => '4.4.6',
        'from_build' => 40,
        'from_commit' => str_repeat('a', 40),
        'from_digest' => 'sha256:'.str_pad(dechex(40), 64, 'c', STR_PAD_LEFT),
        'from_latest_migration' => '2026_10_12_180100_create_coolify_upgrades_table',
        'to_version' => '4.4.6-fork.41',
        'to_base_version' => '4.4.6',
        'to_build' => 41,
        'to_commit' => str_pad(dechex(41), 40, 'b', STR_PAD_LEFT),
        'to_image' => 'ghcr.io/acme/coolify',
        'to_digest' => 'sha256:'.str_pad(dechex(41), 64, 'c', STR_PAD_LEFT),
        'started_at' => now(),
    ], $overrides));
}

it('starts a fork update only with the verified release image and records the checks', function () {
    distributionUpgradeTestPreflight(distributionUpgradeTestPassing());

    $upgrade = StartDistributionUpgrade::run(distributionUpgradeTestRelease(), CoolifyUpgrade::TRIGGER_MANUAL);

    expect($upgrade->state)->toBe(CoolifyUpgradeState::RUNNING)
        ->and($upgrade->to_digest)->toBe('sha256:'.str_pad(dechex(41), 64, 'c', STR_PAD_LEFT))
        ->and($upgrade->from_build)->toBe(40)
        ->and($upgrade->from_digest)->toBe('sha256:'.str_pad(dechex(40), 64, 'c', STR_PAD_LEFT))
        ->and($upgrade->includes_migrations)->toBeTrue()
        ->and($upgrade->preflight)->toHaveCount(2)
        ->and(DistributionTestStartUpgrade::$launched)->toBe([$upgrade->uuid]);
});

it('blocks the update and launches nothing when a required check fails', function () {
    distributionUpgradeTestPreflight([
        new PreflightResult('image_available', 'Release image', PreflightState::FAILED, 'The registry does not have the image.'),
    ]);

    try {
        StartDistributionUpgrade::run(distributionUpgradeTestRelease(), CoolifyUpgrade::TRIGGER_MANUAL);
        $this->fail('The update was not blocked.');
    } catch (DistributionUpgradeRefused $e) {
        expect($e->blocking)->toHaveCount(1)
            ->and($e->upgrade->state)->toBe(CoolifyUpgradeState::PREFLIGHT_FAILED);
    }

    expect(DistributionTestStartUpgrade::$launched)->toBe([]);
});

it('allows an overridable check only with an acknowledgement and a reason', function () {
    distributionUpgradeTestPreflight([
        new PreflightResult('instance_backup', 'Coolify database backup', PreflightState::FAILED, 'No backup.', overridable: true),
    ]);

    expect(fn () => StartDistributionUpgrade::run(distributionUpgradeTestRelease(), CoolifyUpgrade::TRIGGER_MANUAL, null, ['instance_backup']))
        ->toThrow(DistributionUpgradeRefused::class, 'requires a reason');

    $upgrade = StartDistributionUpgrade::run(distributionUpgradeTestRelease(), CoolifyUpgrade::TRIGGER_MANUAL, null, ['instance_backup', 'not_a_check'], 'Snapshot taken by the hosting provider.');

    expect($upgrade->state)->toBe(CoolifyUpgradeState::RUNNING)
        ->and($upgrade->acknowledged_checks)->toBe(['instance_backup'])
        ->and($upgrade->acknowledgement_reason)->toBe('Snapshot taken by the hosting provider.');
});

it('never lets a non-overridable check be acknowledged away', function () {
    distributionUpgradeTestPreflight([
        new PreflightResult('schema_compatibility', 'Database schema', PreflightState::FAILED, 'Older schema.'),
    ]);

    expect(fn () => StartDistributionUpgrade::run(distributionUpgradeTestRelease(), CoolifyUpgrade::TRIGGER_MANUAL, null, ['schema_compatibility'], 'please'))
        ->toThrow(DistributionUpgradeRefused::class, 'blocked by failed checks');
    expect(DistributionTestStartUpgrade::$launched)->toBe([]);
});

it('refuses concurrent update requests', function () {
    distributionUpgradeTestPreflight(distributionUpgradeTestPassing());
    $lock = Cache::lock(StartDistributionUpgrade::LOCK, 300);
    $lock->get();

    expect(fn () => StartDistributionUpgrade::run(distributionUpgradeTestRelease(), CoolifyUpgrade::TRIGGER_MANUAL))
        ->toThrow(DistributionUpgradeRefused::class, 'being processed');

    $lock->release();
    expect(DistributionTestStartUpgrade::$launched)->toBe([]);
});

it('fails the lock check while another update is running', function () {
    distributionUpgradeTestRunning();

    $result = app(DistributionUpgradePreflight::class)->upgradeLockCheck(null);

    expect($result->state)->toBe(PreflightState::FAILED)
        ->and($result->overridable)->toBeFalse();
});

it('rejects release images outside the trusted repository', function () {
    $release = new DistributionRelease('x', '4.4.6', 41, str_repeat('b', 40), 'ghcr.io/evil/coolify', 'sha256:'.str_repeat('c', 64), ['stable'], 'passed');

    expect(fn () => StartDistributionUpgrade::run($release, CoolifyUpgrade::TRIGGER_MANUAL))
        ->toThrow(DistributionUpgradeRefused::class, 'outside the trusted repository');
});

it('refuses distribution updates on official installations', function () {
    BuildIdentity::setCurrent(BuildIdentity::official());

    expect(fn () => StartDistributionUpgrade::run(distributionUpgradeTestRelease(), CoolifyUpgrade::TRIGGER_MANUAL))
        ->toThrow(DistributionUpgradeRefused::class);
});

it('passes only validated, escaped arguments and the pinned digest to the host script', function () {
    $identity = distributionTestIdentity(['signature' => [
        'issuer' => 'https://token.actions.githubusercontent.com',
        'identity_regexp' => '^https://github\.com/acme/coolify/\.github/workflows/distribution-build\.yml@refs/heads/main$',
    ]]);
    $upgrade = distributionUpgradeTestRunning(['to_latest_migration' => '2026_10_12_180100_create_coolify_upgrades_table']);

    $commands = StartDistributionUpgrade::commands($upgrade, $identity, "#!/bin/bash\necho 'x'\n", '1.0.17', 'ghcr.io');

    expect($commands)->toHaveCount(2)
        ->and($commands[0])->toContain(base64_encode("#!/bin/bash\necho 'x'\n"))
        ->and($commands[0])->toEndWith('tee /data/coolify/source/upgrade-distribution.sh > /dev/null')
        ->and($commands[1])->toStartWith('bash /data/coolify/source/upgrade-distribution.sh ')
        ->and($commands[1])->toContain("'--digest' 'sha256:".str_pad(dechex(41), 64, 'c', STR_PAD_LEFT)."'")
        ->and($commands[1])->toContain("'--image' 'ghcr.io/acme/coolify'")
        ->and($commands[1])->toContain("'--expected-migration' '2026_10_12_180100_create_coolify_upgrades_table'")
        ->and($commands[1])->toContain("'--signature-identity-regexp' '^https://github\\.com/acme/coolify/\\.github/workflows/distribution-build\\.yml@refs/heads/main$'")
        ->and($commands[1])->not->toContain('--rollback')
        ->and($commands[1])->not->toContain('cdn.coollabs.io');
});

it('marks an update successful only when build, commit and image digest match', function () {
    $upgrade = distributionUpgradeTestRunning();
    $target = distributionTestIdentity(['build_number' => 41, 'source_commit' => $upgrade->to_commit]);
    distributionTestRunningImage($upgrade->to_digest);

    (new ReconcileDistributionUpgrades)->reconcile($upgrade, $target, null);

    expect($upgrade->fresh()->state)->toBe(CoolifyUpgradeState::SUCCEEDED)
        ->and($upgrade->fresh()->verified_at)->not->toBeNull();
});

it('does not trust the build file alone when the running digest differs', function () {
    $upgrade = distributionUpgradeTestRunning();
    $target = distributionTestIdentity(['build_number' => 41, 'source_commit' => $upgrade->to_commit]);
    distributionTestRunningImage('sha256:'.str_repeat('9', 64));

    (new ReconcileDistributionUpgrades)->reconcile($upgrade, $target, null);

    expect($upgrade->fresh()->state)->toBe(CoolifyUpgradeState::UNVERIFIED);
});

it('keeps an update running while the old version is still up after a restart without a result', function () {
    $upgrade = distributionUpgradeTestRunning();

    (new ReconcileDistributionUpgrades)->reconcile($upgrade, distributionTestIdentity(), null);

    expect($upgrade->fresh()->state)->toBe(CoolifyUpgradeState::RUNNING);
});

it('maps host script results to truthful update states', function (string $result, CoolifyUpgradeState $state) {
    $upgrade = distributionUpgradeTestRunning();

    (new ReconcileDistributionUpgrades)->reconcile($upgrade, distributionTestIdentity(), ReconcileDistributionUpgrades::parseResult("{$upgrade->uuid}|{$result}|message|2026-10-12T10:00:00+00:00"));

    expect($upgrade->fresh()->state)->toBe($state)
        ->and($upgrade->fresh()->message)->toBe('message');
})->with([
    'pull or verification failed' => ['aborted', CoolifyUpgradeState::FAILED],
    'unhealthy without migrations' => ['reverted', CoolifyUpgradeState::REVERTED],
    'unhealthy after migrations' => ['needs_recovery', CoolifyUpgradeState::NEEDS_RECOVERY],
]);

it('ignores results that belong to another update', function () {
    $upgrade = distributionUpgradeTestRunning();
    $other = (string) Str::uuid();

    (new ReconcileDistributionUpgrades)->reconcile($upgrade, distributionTestIdentity(), ReconcileDistributionUpgrades::parseResult("{$other}|aborted|nope|2026-10-12T10:00:00+00:00"));

    expect($upgrade->fresh()->state)->toBe(CoolifyUpgradeState::RUNNING)
        ->and(ReconcileDistributionUpgrades::parseResult('garbage'))->toBeNull();
});

it('marks a silent update as unverified after the timeout', function () {
    $upgrade = distributionUpgradeTestRunning(['started_at' => now()->subMinutes(CoolifyUpgrade::RUNNING_TIMEOUT_MINUTES + 1)]);

    (new ReconcileDistributionUpgrades)->reconcile($upgrade, distributionTestIdentity(), null);

    expect($upgrade->fresh()->state)->toBe(CoolifyUpgradeState::UNVERIFIED);
});

it('offers an image rollback only while no migration ran since the update', function () {
    $currentMigration = app(CoolifyDistribution::class)->latestAppliedMigration();
    $upgrade = distributionUpgradeTestRunning(['from_latest_migration' => $currentMigration]);
    $upgrade->finish(CoolifyUpgradeState::SUCCEEDED);
    BuildIdentity::setCurrent(distributionTestIdentity(['build_number' => 41, 'source_commit' => $upgrade->to_commit]));

    $candidate = app(CoolifyDistribution::class)->rollbackCandidate();
    expect($candidate->imageRollbackSafe)->toBeTrue()
        ->and($candidate->release->build)->toBe(40)
        ->and($candidate->release->latestMigration)->toBe($currentMigration);

    $upgrade->update(['from_latest_migration' => '2026_01_01_000000_older_schema']);

    $candidate = app(CoolifyDistribution::class)->rollbackCandidate();
    expect($candidate->imageRollbackSafe)->toBeFalse()
        ->and($candidate->explanation)->toContain('Restore the database backup');
});

it('does not offer a rollback to an image that was not installed from the channel', function () {
    $upgrade = distributionUpgradeTestRunning(['from_build' => null, 'from_commit' => null]);
    $upgrade->finish(CoolifyUpgradeState::SUCCEEDED);
    BuildIdentity::setCurrent(distributionTestIdentity(['build_number' => 41, 'source_commit' => $upgrade->to_commit]));

    $candidate = app(CoolifyDistribution::class)->rollbackCandidate();

    expect($candidate->imageRollbackSafe)->toBeFalse()
        ->and($candidate->release)->toBeNull();
});

it('rejects releases that do not know the applied migrations', function () {
    $preflight = app(DistributionUpgradePreflight::class);
    $release = distributionUpgradeTestRelease(41, ['migrations' => ['latest' => '2026_01_01_000000_older_schema']]);

    $upgrade = $preflight->schemaCheck($release, '2026_10_12_180100_create_coolify_upgrades_table', false);
    $rollback = $preflight->schemaCheck(distributionUpgradeTestRelease(41, ['migrations' => ['latest' => null]]), '2026_10_12_180100_create_coolify_upgrades_table', true);
    $newer = $preflight->schemaCheck(distributionUpgradeTestRelease(41, ['migrations' => ['latest' => '2026_12_01_000000_newer_schema']]), '2026_10_12_180100_create_coolify_upgrades_table', false);

    expect($upgrade->state)->toBe(PreflightState::FAILED)
        ->and($upgrade->overridable)->toBeFalse()
        ->and($rollback->state)->toBe(PreflightState::FAILED)
        ->and($newer->state)->toBe(PreflightState::PASSED)
        ->and($newer->details['includes_migrations'])->toBeTrue();
});

it('requires a fresh Coolify database backup before updates with migrations', function () {
    Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $preflight = app(DistributionUpgradePreflight::class);

    expect($preflight->backupCheck(true)->state)->toBe(PreflightState::FAILED)
        ->and($preflight->backupCheck(true)->overridable)->toBeTrue()
        ->and($preflight->backupCheck(false)->blocks())->toBeFalse();

    $backup = ScheduledDatabaseBackup::forceCreate([
        'uuid' => (string) Str::uuid(),
        'frequency' => '0 0 * * *',
        'database_type' => StandalonePostgresql::class,
        'database_id' => 0,
        'team_id' => 0,
        'save_s3' => false,
    ]);
    ScheduledDatabaseBackupExecution::forceCreate([
        'uuid' => (string) Str::uuid(),
        'scheduled_database_backup_id' => $backup->id,
        'status' => 'success',
        'finished_at' => now()->subHours(30),
    ]);

    expect($preflight->backupCheck(true)->state)->toBe(PreflightState::FAILED);

    ScheduledDatabaseBackupExecution::forceCreate([
        'uuid' => (string) Str::uuid(),
        'scheduled_database_backup_id' => $backup->id,
        'status' => 'success',
        'finished_at' => now()->subHours(2),
    ]);

    expect($preflight->backupCheck(true)->state)->toBe(PreflightState::PASSED);
});

it('asks for an acknowledgement while deployments are running', function () {
    ApplicationDeploymentQueue::create([
        'application_id' => '1',
        'deployment_uuid' => (string) Str::uuid(),
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]);

    $result = app(DistributionUpgradePreflight::class)->deploymentsCheck();

    expect($result->state)->toBe(PreflightState::FAILED)
        ->and($result->overridable)->toBeTrue();
});

it('reads the free Docker disk space from df output', function () {
    expect(DistributionUpgradePreflight::parseAvailableKb("Filesystem 1024-blocks Used Available Capacity Mounted on\n/dev/sda1 100 50 4194304 50% /var/lib/docker"))->toBe(4194304)
        ->and(DistributionUpgradePreflight::parseAvailableKb(''))->toBeNull();
});

it('looks up release images anonymously and handles missing credentials safely', function () {
    Http::fake([
        'ghcr.io/v2/acme/coolify/manifests/sha256:'.str_repeat('1', 64) => Http::response(null, 401, ['WWW-Authenticate' => 'Bearer realm="https://ghcr.io/token",service="ghcr.io",scope="repository:acme/coolify:pull"']),
        'ghcr.io/token*' => Http::response(['token' => 'anonymous']),
    ]);

    expect((new OciRegistryClient)->manifestStatus('ghcr.io/acme/coolify', 'sha256:'.str_repeat('1', 64)))->toBe(ManifestLookup::AUTH_REQUIRED);

    Http::fake([
        'ghcr.io/token*' => Http::response(null, 403),
        'ghcr.io/v2/*' => Http::response(null, 401, ['WWW-Authenticate' => 'Bearer realm="https://ghcr.io/token",service="ghcr.io"']),
    ]);
    expect((new OciRegistryClient)->manifestStatus('ghcr.io/acme/private', 'sha256:'.str_repeat('2', 64)))->toBe(ManifestLookup::AUTH_REQUIRED);
});

it('reports missing images and rejects invalid references', function () {
    Http::fake(['ghcr.io/v2/*' => Http::response(null, 404)]);
    $client = new OciRegistryClient;

    expect($client->manifestStatus('ghcr.io/acme/coolify', 'sha256:'.str_repeat('3', 64)))->toBe(ManifestLookup::NOT_FOUND)
        ->and($client->manifestStatus('ghcr.io/acme/coolify', 'latest; rm -rf /'))->toBe(ManifestLookup::INVALID_REFERENCE)
        ->and($client->manifestStatus('not a repository', 'latest'))->toBe(ManifestLookup::INVALID_REFERENCE);

    $preflight = app(DistributionUpgradePreflight::class)->registryCheck(distributionUpgradeTestRelease());
    expect($preflight->state)->toBe(PreflightState::FAILED);
});

it('does not trust token realms on other hosts', function () {
    Http::fake([
        'ghcr.io/v2/*' => Http::response(null, 401, ['WWW-Authenticate' => 'Bearer realm="https://attacker.test/token",service="ghcr.io"']),
        'attacker.test/*' => Http::response(['token' => 'x']),
    ]);

    expect((new OciRegistryClient)->manifestStatus('ghcr.io/acme/coolify', 'sha256:'.str_repeat('4', 64)))->toBe(ManifestLookup::AUTH_REQUIRED);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'attacker.test'));
});

it('keeps the retained rollback image out of Docker cleanup', function () {
    $command = (fn () => $this->buildImagePruneCommand(collect(), '1.0.17'))->call(new CleanupDocker);

    expect($command)->toContain('^'.CleanupDocker::DISTRIBUTION_ROLLBACK_IMAGE.'$');
});

it('starts a reviewed update from the upgrade component and rejects a changed release', function () {
    distributionUpgradeTestPreflight(distributionUpgradeTestPassing());
    distributionTestCacheManifest(distributionTestManifest([distributionTestRelease(40, ['commit' => str_repeat('a', 40)]), distributionTestRelease(41)], ['stable' => 41]));
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);
    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    $component = Livewire::test(Upgrade::class);
    $component->call('preflight')->assertReturned(fn ($result) => $result['target']['build'] === 41 && $result['blocked'] === false);
    $component->call('upgrade')->assertReturned(true);

    expect(DistributionTestStartUpgrade::$launched)->toHaveCount(1);

    $reviewed = Livewire::test(Upgrade::class)->assertSet('targetBuild', 41);
    distributionTestCacheManifest(distributionTestManifest([distributionTestRelease(40, ['commit' => str_repeat('a', 40)]), distributionTestRelease(42)], ['stable' => 42]));
    $reviewed->call('upgrade')->assertReturned(false);

    expect(DistributionTestStartUpgrade::$launched)->toHaveCount(1);
});
