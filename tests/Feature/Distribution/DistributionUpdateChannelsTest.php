<?php

use App\Actions\Distribution\RunAutomaticDistributionUpdate;
use App\Actions\Distribution\StartDistributionUpgrade;
use App\Actions\Server\UpdateCoolify;
use App\Jobs\CheckForUpdatesJob;
use App\Jobs\CheckHelperImageJob;
use App\Jobs\CheckTraefikVersionJob;
use App\Jobs\UpdateCoolifyJob;
use App\Livewire\Settings\Updates;
use App\Livewire\Upgrade;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Distribution\BuildIdentity;
use App\Services\Distribution\CoolifyDistribution;
use App\Services\Distribution\DistributionUpgradeRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    InstanceSettings::forceCreate(['id' => 0, 'is_auto_update_enabled' => true]);
    Once::flush();
    $this->versionsBackup = File::exists(base_path('versions.json')) ? File::get(base_path('versions.json')) : null;
});

afterEach(function () {
    if ($this->versionsBackup !== null) {
        File::put(base_path('versions.json'), $this->versionsBackup);
    }
    invalidate_versions_cache();
    BuildIdentity::setCurrent(null);
});

function distributionChannelsTestOfficialCdn(string $version): array
{
    return [
        'coolify' => [
            'v4' => ['version' => $version],
            'helper' => ['version' => '9.9.9'],
            'sentinel' => ['version' => '1.0.2'],
        ],
        'traefik' => ['v3.6' => '3.6.25'],
    ];
}

function distributionChannelsTestInstanceAdmin(): User
{
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);
    test()->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    return $user;
}

it('keeps detecting official updates on official installations', function () {
    Queue::fake();
    config(['constants.coolify.version' => '4.4.6']);
    Http::fake(['*' => Http::response(distributionChannelsTestOfficialCdn('4.4.7'))]);

    (new CheckForUpdatesJob)->handle();

    expect((bool) InstanceSettings::findOrFail(0)->new_version_available)->toBeTrue();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'manifest.test'));
});

it('detects fork updates from the distribution manifest on fork installations', function () {
    Queue::fake();
    BuildIdentity::setCurrent(distributionTestIdentity());
    config(['constants.coolify.version' => '4.4.6']);
    Http::fake([
        'manifest.test/*' => Http::response(distributionTestManifest([distributionTestRelease(41)], ['stable' => 41])),
        '*' => Http::response(distributionChannelsTestOfficialCdn('4.4.6')),
    ]);

    (new CheckForUpdatesJob)->handle();

    $state = app(CoolifyDistribution::class)->state();
    expect((bool) InstanceSettings::findOrFail(0)->new_version_available)->toBeTrue()
        ->and($state->updateAvailable)->toBeTrue()
        ->and($state->candidate->version)->toBe('4.4.6-fork.41');
});

it('reports a new official release separately without offering it as an update on forks', function () {
    Queue::fake();
    BuildIdentity::setCurrent(distributionTestIdentity());
    config(['constants.coolify.version' => '4.4.6']);
    Http::fake([
        'manifest.test/*' => Http::response(distributionTestManifest([distributionTestRelease(40, ['commit' => str_repeat('a', 40)])], ['stable' => 40])),
        '*' => Http::response(distributionChannelsTestOfficialCdn('4.4.9')),
    ]);

    (new CheckForUpdatesJob)->handle();

    expect((bool) InstanceSettings::findOrFail(0)->new_version_available)->toBeFalse()
        ->and(app(CoolifyDistribution::class)->officialLatestVersion())->toBe('4.4.9')
        ->and(app(CoolifyDistribution::class)->state()->updateAvailable)->toBeFalse();
});

it('keeps helper and Traefik version updates working on fork installations', function () {
    Queue::fake();
    BuildIdentity::setCurrent(distributionTestIdentity());
    config(['constants.coolify.version' => '4.4.6']);
    Http::fake([
        'manifest.test/*' => Http::response(null, 503),
        '*' => Http::response(distributionChannelsTestOfficialCdn('4.4.6')),
    ]);

    (new CheckForUpdatesJob)->handle();
    (new CheckHelperImageJob)->handle();

    Queue::assertPushed(CheckTraefikVersionJob::class);
    expect(InstanceSettings::findOrFail(0)->helper_version)->toBe('9.9.9')
        ->and(data_get(json_decode(File::get(base_path('versions.json')), true), 'traefik.v3.6'))->toBe('3.6.25');
});

it('keeps the last known manifest and reports the error when the manifest cannot be downloaded', function () {
    BuildIdentity::setCurrent(distributionTestIdentity());
    distributionTestCacheManifest(distributionTestManifest([distributionTestRelease(41)], ['stable' => 41]));
    Http::fake(['manifest.test/*' => Http::response('not json', 200)]);

    $state = app(CoolifyDistribution::class)->refresh();

    expect($state->check->error)->toContain('not valid JSON')
        ->and($state->check->stale)->toBeTrue()
        ->and($state->candidate->build)->toBe(41);
});

it('refuses the official upgrade script on fork installations', function () {
    BuildIdentity::setCurrent(distributionTestIdentity());
    Http::fake();

    expect(fn () => (new UpdateCoolify)->handle(manual_update: true))
        ->toThrow(DistributionUpgradeRefused::class, 'official update path');

    Http::assertNothingSent();
});

it('does not install fork releases automatically unless explicitly enabled', function () {
    BuildIdentity::setCurrent(distributionTestIdentity());
    distributionTestCacheManifest(distributionTestManifest([distributionTestRelease(41)], ['stable' => 41]));
    Http::fake(['manifest.test/*' => Http::response(distributionTestManifest([distributionTestRelease(41)], ['stable' => 41]))]);
    $started = Mockery::mock(StartDistributionUpgrade::class);
    $started->shouldNotReceive('handle');
    app()->instance(StartDistributionUpgrade::class, $started);

    (new UpdateCoolifyJob)->handle();

    expect(RunAutomaticDistributionUpdate::run())->toBeNull();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'cdn.coollabs.io'));
});

it('starts automatic fork updates without acknowledging any failed check', function () {
    BuildIdentity::setCurrent(distributionTestIdentity());
    InstanceSettings::query()->whereKey(0)->update(['is_distribution_auto_update_enabled' => true]);
    Http::fake(['manifest.test/*' => Http::response(distributionTestManifest([distributionTestRelease(41)], ['stable' => 41]))]);
    $started = Mockery::mock(StartDistributionUpgrade::class);
    $started->shouldReceive('handle')
        ->once()
        ->withArgs(fn ($release, $trigger, $user = null, $acknowledged = [], $reason = null) => $release->build === 41 && $trigger === 'automatic' && $acknowledged === [])
        ->andThrow(new DistributionUpgradeRefused('blocked'));
    app()->instance(StartDistributionUpgrade::class, $started);

    expect(RunAutomaticDistributionUpdate::run())->toBeNull();
});

it('shows the fork channel in the upgrade component instead of the official version', function () {
    BuildIdentity::setCurrent(distributionTestIdentity());
    distributionTestCacheManifest(distributionTestManifest([distributionTestRelease(40, ['commit' => str_repeat('a', 40)]), distributionTestRelease(41)], ['stable' => 41]));

    Livewire::test(Upgrade::class)
        ->assertSet('isCustomDistribution', true)
        ->assertSet('isUpgradeAvailable', true)
        ->assertSet('currentVersion', '4.4.6-fork.40')
        ->assertSet('latestVersion', '4.4.6-fork.41')
        ->assertSet('targetBuild', 41);
});

it('keeps the official upgrade component behaviour on official installations', function () {
    config(['constants.coolify.version' => '4.4.6']);
    Cache::put('coolify:versions:all', distributionChannelsTestOfficialCdn('4.4.7'), 3600);
    InstanceSettings::query()->whereKey(0)->update(['new_version_available' => true]);

    Livewire::test(Upgrade::class)
        ->assertSet('isCustomDistribution', false)
        ->assertSet('isUpgradeAvailable', true)
        ->assertSet('latestVersion', '4.4.7');
});

it('only lets instance admins run the distribution preflight', function () {
    BuildIdentity::setCurrent(distributionTestIdentity());
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => ['id' => $team->id]]);

    Livewire::test(Upgrade::class)
        ->call('preflight')
        ->assertForbidden();
});

it('shows distribution channels on the update settings page of a fork', function () {
    BuildIdentity::setCurrent(distributionTestIdentity());
    distributionTestRunningImage('sha256:'.str_pad(dechex(40), 64, 'c', STR_PAD_LEFT));
    distributionTestCacheManifest(distributionTestManifest(
        [distributionTestRelease(40, ['commit' => str_repeat('a', 40)]), distributionTestRelease(41)],
        ['stable' => 41],
        ['upstream' => ['repository' => 'coollabsio/coolify', 'latest_release' => '4.4.7', 'sync' => ['state' => 'pending_review']]],
    ));
    Server::factory()->create(['id' => 0, 'team_id' => (Team::find(0) ?? Team::factory()->create(['id' => 0]))->id]);
    distributionChannelsTestInstanceAdmin();

    Livewire::test(Updates::class)
        ->assertSet('isCustomDistribution', true)
        ->assertSee('Running version')
        ->assertSee('acme/coolify')
        ->assertSee('Matches release')
        ->assertSee('4.4.6-fork.41')
        ->assertSee('Official Coolify')
        ->assertSee('Behind upstream')
        ->assertSee('Install distribution releases automatically');
});

it('rejects unknown distribution channels', function () {
    BuildIdentity::setCurrent(distributionTestIdentity());
    distributionTestRunningImage(null);
    Server::factory()->create(['id' => 0, 'team_id' => (Team::find(0) ?? Team::factory()->create(['id' => 0]))->id]);
    distributionChannelsTestInstanceAdmin();

    Livewire::test(Updates::class)
        ->set('distribution_channel', 'nightly')
        ->call('instantSave')
        ->assertHasErrors(['distribution_channel']);

    expect(InstanceSettings::findOrFail(0)->distribution_channel)->toBe('stable');
});

it('keeps distribution auto updates disabled by default', function () {
    expect((bool) InstanceSettings::findOrFail(0)->is_distribution_auto_update_enabled)->toBeFalse()
        ->and(InstanceSettings::findOrFail(0)->distribution_channel)->toBe('stable');
});

it('derives the reported Coolify version from the installed build, not from the environment', function () {
    config(['constants.coolify.version' => '4.4.9']);

    distributionTestIdentity(['base_version' => '4.4.6'])->applyToConfiguration();

    expect(config('constants.coolify.version'))->toBe('4.4.6')
        ->and(config('constants.coolify.configured_version'))->toBe('4.4.9')
        ->and(distributionTestIdentity()->environmentVersionMismatch(config('constants.coolify.configured_version')))->toBeTrue();
});

it('leaves the configured version untouched on official installations', function () {
    config(['constants.coolify.version' => '4.4.9']);

    BuildIdentity::official()->applyToConfiguration();

    expect(config('constants.coolify.version'))->toBe('4.4.9');
});
