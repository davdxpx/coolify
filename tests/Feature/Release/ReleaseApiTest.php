<?php

use App\Actions\Release\CreateRelease;
use App\Enums\ReleaseState;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseGroup;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\Release\ReleaseRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    Bus::fake([ApplicationDeploymentJob::class]);
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true]);
    $destination = StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'release-api-'.fake()->unique()->word()]);
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'name' => 'api',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'git_commit_sha' => 'HEAD',
        'status' => 'running:healthy',
    ]);
    $this->group = ReleaseGroup::create(['team_id' => $this->team->id, 'environment_id' => $environment->id, 'name' => 'Backend']);
    $this->group->members()->create(['resource_type' => Application::class, 'resource_id' => $this->application->id]);
});

function releaseApiToken(User $user, Team $team, array $abilities): string
{
    $token = $user->createToken('release-api', $abilities);
    $token->accessToken->forceFill(['team_id' => $team->id])->save();

    return $token->plainTextToken;
}

it('lists release groups of the token team only', function () {
    $otherTeam = Team::factory()->create();
    $otherEnvironment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $otherTeam->id])->id]);
    ReleaseGroup::create(['team_id' => $otherTeam->id, 'environment_id' => $otherEnvironment->id, 'name' => 'Foreign']);

    $response = $this->withToken(releaseApiToken($this->user, $this->team, ['read']))->getJson('/api/v1/release-groups');

    $response->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.uuid', $this->group->uuid)
        ->assertJsonPath('0.members.0.resource_uuid', $this->application->uuid)
        ->assertJsonPath('0.latest_release', null);
});

it('starts a release once per idempotency key and reports its members', function () {
    $token = releaseApiToken($this->user, $this->team, ['deploy', 'read']);

    $first = $this->withToken($token)
        ->postJson("/api/v1/release-groups/{$this->group->uuid}/releases", ['commit' => 'main', 'notes' => 'Weekly release'], ['Idempotency-Key' => 'pipeline-1']);
    $second = $this->withToken($token)
        ->postJson("/api/v1/release-groups/{$this->group->uuid}/releases", ['commit' => 'main'], ['Idempotency-Key' => 'pipeline-1']);

    $first->assertCreated()
        ->assertJsonPath('state', ReleaseState::RUNNING->value)
        ->assertJsonPath('requested_commit', 'main')
        ->assertJsonPath('members.0.resource_uuid', $this->application->uuid)
        ->assertJsonPath('members.0.state', 'deploying');
    $second->assertOk()->assertJsonPath('uuid', $first->json('uuid'));
    expect(Release::count())->toBe(1)
        ->and(ApplicationDeploymentQueue::count())->toBe(1);

    $this->withToken($token)->postJson("/api/v1/release-groups/{$this->group->uuid}/releases")
        ->assertStatus(409);

    $this->withToken($token)->getJson('/api/v1/releases/'.$first->json('uuid'))
        ->assertOk()
        ->assertJsonPath('members.0.deployment_uuid', ApplicationDeploymentQueue::first()->deployment_uuid);
});

it('requires the deploy ability to start or cancel a release', function () {
    $token = releaseApiToken($this->user, $this->team, ['read']);

    $this->withToken($token)->postJson("/api/v1/release-groups/{$this->group->uuid}/releases")->assertForbidden();
    expect(Release::count())->toBe(0);
});

it('hides release groups and releases of other teams', function () {
    $stranger = User::factory()->create();
    $strangerTeam = Team::factory()->create();
    $strangerTeam->members()->attach($stranger->id, ['role' => 'owner']);
    $token = releaseApiToken($stranger, $strangerTeam, ['deploy', 'read']);
    $release = CreateRelease::run($this->group, Release::TRIGGER_UI, $this->user);

    $this->withToken($token)->getJson("/api/v1/release-groups/{$this->group->uuid}")->assertNotFound();
    $this->withToken($token)->postJson("/api/v1/release-groups/{$this->group->uuid}/releases")->assertNotFound();
    $this->withToken($token)->getJson("/api/v1/releases/{$release->uuid}")->assertNotFound();
    $this->withToken($token)->postJson("/api/v1/releases/{$release->uuid}/cancel")->assertNotFound();
});

it('refuses a release for a team member without admin rights', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);

    $this->withToken(releaseApiToken($member, $this->team, ['deploy']))
        ->postJson("/api/v1/release-groups/{$this->group->uuid}/releases")
        ->assertForbidden();

    expect(fn () => CreateRelease::run($this->group, Release::TRIGGER_API, $member))
        ->toThrow(ReleaseRefused::class, 'You need at least admin permissions in this team to start a release.')
        ->and(Release::count())->toBe(0);
});

it('cancels a release and validates the resume mode', function () {
    $token = releaseApiToken($this->user, $this->team, ['deploy', 'read']);
    $uuid = $this->withToken($token)->postJson("/api/v1/release-groups/{$this->group->uuid}/releases")->json('uuid');

    $this->withToken($token)->postJson("/api/v1/releases/{$uuid}/resume", ['mode' => 'rewind'])->assertUnprocessable();
    $this->withToken($token)->postJson("/api/v1/releases/{$uuid}/resume", ['mode' => 'retry'])->assertStatus(409);
    $this->withToken($token)->postJson("/api/v1/releases/{$uuid}/cancel")
        ->assertOk()
        ->assertJsonPath('state', ReleaseState::CANCELLED->value);

    $this->withToken($token)->getJson("/api/v1/release-groups/{$this->group->uuid}/releases")
        ->assertOk()
        ->assertJsonPath('0.uuid', $uuid);
});
