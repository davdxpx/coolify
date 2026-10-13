<?php

use App\Actions\Release\CreateRelease;
use App\Enums\ReleaseState;
use App\Jobs\ApplicationDeploymentJob;
use App\Livewire\Project\Release\Group;
use App\Livewire\Project\Release\Index;
use App\Livewire\Project\Release\Show;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseGroup;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    Bus::fake([ApplicationDeploymentJob::class]);
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'admin']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true]);
    $destination = StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'release-ui-'.fake()->unique()->word()]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->applications = collect(['api', 'web'])->map(fn (string $name) => Application::factory()->create([
        'name' => $name,
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'git_commit_sha' => 'HEAD',
        'status' => 'running:healthy',
    ]));
    $this->route = ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid];
});

it('creates a release group from the environment', function () {
    Livewire::test(Index::class, $this->route)
        ->set('name', 'Web stack')
        ->call('create')
        ->assertHasNoErrors();

    $group = ReleaseGroup::firstOrFail();
    expect($group->team_id)->toBe($this->team->id)
        ->and($group->environment_id)->toBe($this->environment->id);
});

it('adds applications, saves dependencies and rejects invalid ones', function () {
    $group = ReleaseGroup::create(['team_id' => $this->team->id, 'environment_id' => $this->environment->id, 'name' => 'Web stack']);
    $params = [...$this->route, 'release_group_uuid' => $group->uuid];

    $component = Livewire::test(Group::class, $params)
        ->set('newApplicationUuid', $this->applications[0]->uuid)
        ->set('newPhase', 1)
        ->call('addMember')
        ->set('newApplicationUuid', $this->applications[1]->uuid)
        ->set('newPhase', 1)
        ->call('addMember');

    [$api, $web] = $group->members()->get()->all();
    $component->set("memberSettings.{$web->uuid}.depends_on", [$api->uuid])
        ->set("memberSettings.{$web->uuid}.phase", 2)
        ->call('saveMembers')
        ->assertDispatched('success');
    expect($web->fresh()->dependencies())->toBe([$api->uuid])
        ->and($web->fresh()->phase)->toBe(2);

    $component->set("memberSettings.{$api->uuid}.depends_on", [$web->uuid])
        ->call('saveMembers')
        ->assertDispatched('error');
    expect($api->fresh()->dependencies())->toBe([]);
});

it('does not add an application from another environment', function () {
    $group = ReleaseGroup::create(['team_id' => $this->team->id, 'environment_id' => $this->environment->id, 'name' => 'Web stack']);
    $otherEnvironment = Environment::factory()->create(['project_id' => $this->project->id]);
    $foreign = Application::factory()->create(['environment_id' => $otherEnvironment->id, 'destination_id' => $this->applications[0]->destination_id, 'destination_type' => StandaloneDocker::class]);

    Livewire::test(Group::class, [...$this->route, 'release_group_uuid' => $group->uuid])
        ->set('newApplicationUuid', $foreign->uuid)
        ->call('addMember');

    expect($group->members()->count())->toBe(0);
});

it('starts a release from the group page and shows it', function () {
    $group = ReleaseGroup::create(['team_id' => $this->team->id, 'environment_id' => $this->environment->id, 'name' => 'Web stack']);
    $group->members()->create(['resource_type' => Application::class, 'resource_id' => $this->applications[0]->id]);
    $params = [...$this->route, 'release_group_uuid' => $group->uuid];

    Livewire::test(Group::class, $params)
        ->set('releaseCommit', 'v1.2.3')
        ->call('startRelease')
        ->assertRedirect();

    $release = Release::firstOrFail();
    expect($release->requested_commit)->toBe('v1.2.3')
        ->and($release->state)->toBe(ReleaseState::RUNNING);

    Livewire::test(Show::class, [...$params, 'release_uuid' => $release->uuid])
        ->assertSee('Release of Web stack')
        ->assertSee('api')
        ->call('cancel')
        ->assertDispatched('success');
    expect($release->fresh()->state)->toBe(ReleaseState::CANCELLED);
});

it('renders the release pages over HTTP', function () {
    $group = ReleaseGroup::create(['team_id' => $this->team->id, 'environment_id' => $this->environment->id, 'name' => 'Web stack']);
    $group->members()->create(['resource_type' => Application::class, 'resource_id' => $this->applications[0]->id]);
    $release = CreateRelease::run($group, Release::TRIGGER_UI, $this->user);

    $this->get(route('project.release-group.index', $this->route))->assertOk()->assertSee('Web stack');
    $this->get(route('project.release-group.show', [...$this->route, 'release_group_uuid' => $group->uuid]))->assertOk()->assertSee('Release history');
    $this->get(route('project.release.show', [...$this->route, 'release_group_uuid' => $group->uuid, 'release_uuid' => $release->uuid]))->assertOk()->assertSee('Checks before the release');
});

it('does not open release groups of another team', function () {
    $otherTeam = Team::factory()->create();
    $otherEnvironment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $otherTeam->id])->id]);
    $foreign = ReleaseGroup::create(['team_id' => $otherTeam->id, 'environment_id' => $otherEnvironment->id, 'name' => 'Foreign']);

    $this->get(route('project.release-group.show', [...$this->route, 'release_group_uuid' => $foreign->uuid]))->assertNotFound();
});

it('lets a member view but not change or start a release group', function () {
    $group = ReleaseGroup::create(['team_id' => $this->team->id, 'environment_id' => $this->environment->id, 'name' => 'Web stack']);
    $group->members()->create(['resource_type' => Application::class, 'resource_id' => $this->applications[0]->id]);
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);

    Livewire::test(Group::class, [...$this->route, 'release_group_uuid' => $group->uuid])
        ->assertDontSee('Start release')
        ->set('name', 'Renamed')
        ->call('saveSettings')
        ->call('startRelease');

    expect($group->fresh()->name)->toBe('Web stack')
        ->and(Release::count())->toBe(0);
});

it('stores the release window and backup age as release checks', function () {
    $group = ReleaseGroup::create(['team_id' => $this->team->id, 'environment_id' => $this->environment->id, 'name' => 'Web stack']);

    Livewire::test(Group::class, [...$this->route, 'release_group_uuid' => $group->uuid])
        ->set('windowEnabled', true)
        ->set('windowDays', ['2', '3'])
        ->set('windowStart', '08:00')
        ->set('windowEnd', '16:30')
        ->set('windowTimezone', 'Europe/Berlin')
        ->set('backupMaxAgeHours', 12)
        ->call('saveSettings')
        ->assertDispatched('success');

    expect($group->fresh()->preflight_policy)->toBe([
        'release_window' => ['days' => [2, 3], 'start' => '08:00', 'end' => '16:30', 'timezone' => 'Europe/Berlin'],
        'backup_max_age_hours' => 12,
    ]);

    Livewire::test(Group::class, [...$this->route, 'release_group_uuid' => $group->uuid])
        ->assertSet('windowEnabled', true)
        ->assertSet('windowTimezone', 'Europe/Berlin')
        ->set('windowEnabled', false)
        ->set('backupMaxAgeHours', null)
        ->call('saveSettings');

    expect($group->fresh()->preflight_policy)->toBeNull();
});
