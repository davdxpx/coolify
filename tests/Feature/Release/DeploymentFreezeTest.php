<?php

use App\Actions\Release\CreateRelease;
use App\Actions\Release\ResumeRelease;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ReleaseState;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\ReconcileReleasesJob;
use App\Livewire\DeploymentFreezes\Index as FreezesIndex;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\AuditEvent;
use App\Models\DeploymentFreeze;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseGroup;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Services\Deployment\BuildCapacity;
use App\Services\Deployment\DeploymentArtifacts;
use App\Services\Release\ReleaseRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    Bus::fake([ApplicationDeploymentJob::class]);
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'admin']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true, 'concurrent_builds' => 5]);
    $this->destination = StandaloneDocker::factory()->create(['server_id' => $this->server->id, 'network' => 'freeze-'.fake()->unique()->word()]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'name' => 'api',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'git_commit_sha' => 'HEAD',
        'status' => 'running:healthy',
    ]);
});

function freezeTest(object $test, array $attributes = []): DeploymentFreeze
{
    return DeploymentFreeze::create([
        'team_id' => $test->team->id,
        'reason' => 'Year-end freeze',
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
        ...$attributes,
    ]);
}

it('blocks every deployment path while a freeze covers the application', function () {
    freezeTest($this);

    $result = queue_application_deployment($this->application, 'frozen-1');

    expect($result['status'])->toBe('skipped')
        ->and($result['message'])->toContain('Deployments are frozen')
        ->and($result['freeze_uuid'])->toBe(DeploymentFreeze::first()->uuid)
        ->and(ApplicationDeploymentQueue::count())->toBe(0)
        ->and(AuditEvent::query()->where('event', 'system.deployment.freeze_blocked')->exists())->toBeTrue();
});

it('ignores planned, ended and unrelated freezes', function () {
    $otherEnvironment = Environment::factory()->create(['project_id' => $this->project->id]);
    freezeTest($this, ['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);
    freezeTest($this, ['ended_at' => now()->subMinute()]);
    freezeTest($this, ['ends_at' => now()->subMinute(), 'starts_at' => now()->subHours(2)]);
    freezeTest($this, ['project_id' => $this->project->id, 'environment_id' => $otherEnvironment->id]);
    freezeTest($this, ['team_id' => Team::factory()->create()->id]);

    queue_application_deployment($this->application, 'not-frozen');

    expect(ApplicationDeploymentQueue::count())->toBe(1);
});

it('covers every environment of a frozen project', function () {
    freezeTest($this, ['project_id' => $this->project->id]);

    expect(queue_application_deployment($this->application, 'project-frozen')['status'])->toBe('skipped')
        ->and(ApplicationDeploymentQueue::count())->toBe(0);
});

it('allows rollbacks, previews, additional servers and audited overrides as configured', function () {
    $freeze = freezeTest($this);
    ApplicationPreview::forceCreate(['application_id' => $this->application->id, 'pull_request_id' => 7, 'pull_request_html_url' => 'https://example.com/pr/7']);

    queue_application_deployment($this->application, 'rollback', rollback: true);
    queue_application_deployment($this->application, 'preview', pull_request_id: 7);
    queue_application_deployment($this->application, 'child', commit: 'child123', parent_deployment_uuid: 'parent');
    queue_application_deployment($this->application, 'override', commit: 'abc123', freeze_override_reason: 'Security fix');

    expect(ApplicationDeploymentQueue::query()->pluck('deployment_uuid')->sort()->values()->all())->toBe(['child', 'override', 'preview', 'rollback'])
        ->and(AuditEvent::query()->where('event', 'deployment.freeze_overridden')->exists())->toBeTrue();

    $freeze->update(['allow_rollbacks' => false, 'allow_override' => false]);
    expect(queue_application_deployment($this->application, 'rollback-2', commit: 'def456', rollback: true)['status'])->toBe('skipped')
        ->and(queue_application_deployment($this->application, 'override-2', commit: 'def456', freeze_override_reason: 'Please')['status'])->toBe('skipped')
        ->and(ApplicationDeploymentQueue::count())->toBe(4);
});

it('reports a frozen resource through the API without queueing anything', function () {
    freezeTest($this);
    $token = $this->user->createToken('freeze', ['deploy']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();

    $this->withToken($token->plainTextToken)
        ->postJson("/api/v1/deploy?uuid={$this->application->uuid}")
        ->assertOk()
        ->assertJsonPath('deployments.0.message', fn (string $message) => str_contains($message, 'Year-end freeze'))
        ->assertJsonMissingPath('deployments.0.deployment_uuid');

    $this->withToken($token->plainTextToken)
        ->postJson("/api/v1/applications/{$this->application->uuid}/start")
        ->assertOk()
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'Year-end freeze'));

    expect(ApplicationDeploymentQueue::count())->toBe(0);
});

it('pauses a release during a freeze and continues after an acknowledged override', function () {
    freezeTest($this);
    $group = ReleaseGroup::create(['team_id' => $this->team->id, 'environment_id' => $this->environment->id, 'name' => 'Backend']);
    $group->members()->create(['resource_type' => Application::class, 'resource_id' => $this->application->id]);

    $release = CreateRelease::run($group, Release::TRIGGER_UI, $this->user);
    expect($release->state)->toBe(ReleaseState::ATTENTION_REQUIRED)
        ->and(collect($release->preflight)->firstWhere('id', 'deployment_freeze')['state'])->toBe('failed');

    expect(fn () => ResumeRelease::run($release, ResumeRelease::OVERRIDE, $this->user, acknowledged: ['deployment_freeze']))
        ->toThrow(ReleaseRefused::class, 'reason');

    ResumeRelease::run($release, ResumeRelease::OVERRIDE, $this->user, acknowledged: ['deployment_freeze', 'servers'], reason: 'Hotfix for checkout');

    $release->refresh();
    expect($release->state)->toBe(ReleaseState::RUNNING)
        ->and($release->acknowledged_checks)->toBe(['deployment_freeze'])
        ->and(ApplicationDeploymentQueue::count())->toBe(1)
        ->and(AuditEvent::query()->where('event', 'deployment.freeze_overridden')->exists())->toBeTrue();
});

it('blocks a release outside its release window unless acknowledged', function () {
    $group = ReleaseGroup::create([
        'team_id' => $this->team->id,
        'environment_id' => $this->environment->id,
        'name' => 'Backend',
        'preflight_policy' => ['release_window' => ['days' => [((int) now('UTC')->addDay()->format('N'))], 'start' => '00:00', 'end' => '23:59', 'timezone' => 'UTC']],
    ]);
    $group->members()->create(['resource_type' => Application::class, 'resource_id' => $this->application->id]);

    $release = CreateRelease::run($group, Release::TRIGGER_API, $this->user, acknowledged: ['release_window'], acknowledgementReason: 'Planned exception');
    expect($release->state)->toBe(ReleaseState::RUNNING)
        ->and(collect($release->preflight)->firstWhere('id', 'release_window')['state'])->toBe('failed');

    ApplicationDeploymentQueue::query()->update(['status' => ApplicationDeploymentStatus::FINISHED->value]);
    (new ReconcileReleasesJob)->handle();
    $second = CreateRelease::run($group, Release::TRIGGER_API, $this->user);
    expect($second->state)->toBe(ReleaseState::ATTENTION_REQUIRED)
        ->and($second->attention_reason)->toContain('release window');
});

it('requires recent database backups when the group asks for them', function () {
    $database = StandalonePostgresql::create([
        'name' => 'main-db',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'app',
        'image' => 'postgres:16-alpine',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
    $backup = ScheduledDatabaseBackup::create([
        'team_id' => $this->team->id,
        'frequency' => '0 * * * *',
        'database_type' => $database->getMorphClass(),
        'database_id' => $database->id,
        'enabled' => true,
    ]);
    $group = ReleaseGroup::create(['team_id' => $this->team->id, 'environment_id' => $this->environment->id, 'name' => 'Backend', 'preflight_policy' => ['backup_max_age_hours' => 6]]);
    $group->members()->create(['resource_type' => Application::class, 'resource_id' => $this->application->id]);

    $blocked = CreateRelease::run($group, Release::TRIGGER_UI, $this->user);
    expect($blocked->state)->toBe(ReleaseState::ATTENTION_REQUIRED)
        ->and($blocked->attention_reason)->toContain('main-db');

    ScheduledDatabaseBackupExecution::create(['scheduled_database_backup_id' => $backup->id, 'status' => 'success', 'finished_at' => now()->subHour()]);
    ResumeRelease::run($blocked, ResumeRelease::RETRY, $this->user);

    expect($blocked->refresh()->state)->toBe(ReleaseState::RUNNING);
});

it('manages freezes from the freeze page', function () {
    $this->actingAs($this->user);

    Livewire::test(FreezesIndex::class)
        ->set('reason', 'Black Friday')
        ->set('scope', "environment:{$this->environment->uuid}")
        ->set('startsAt', now()->subMinute()->format('Y-m-d\TH:i'))
        ->call('create')
        ->assertHasNoErrors();

    $freeze = DeploymentFreeze::firstOrFail();
    expect($freeze->environment_id)->toBe($this->environment->id)
        ->and($freeze->isActive())->toBeTrue();

    Livewire::test(FreezesIndex::class)->call('end', $freeze->uuid);
    expect($freeze->fresh()->ended_at)->not->toBeNull();

    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);
    Livewire::test(FreezesIndex::class)->set('reason', 'Nope')->call('create');
    expect(DeploymentFreeze::count())->toBe(1);
});

it('manages freezes through the API within the token team', function () {
    $token = $this->user->createToken('freeze', ['write', 'read']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();

    $created = $this->withToken($token->plainTextToken)->postJson('/api/v1/deployment-freezes', [
        'reason' => 'Migration weekend',
        'project_uuid' => $this->project->uuid,
        'ends_at' => now()->addDay()->toIso8601String(),
    ])->assertCreated()->assertJsonPath('scope', 'project')->assertJsonPath('is_active', true);

    $this->withToken($token->plainTextToken)->getJson('/api/v1/deployment-freezes')->assertOk()->assertJsonCount(1);
    $this->withToken($token->plainTextToken)->postJson('/api/v1/deployment-freezes/'.$created->json('uuid').'/end')
        ->assertOk()->assertJsonPath('is_active', false);

    $other = Team::factory()->create();
    $foreign = DeploymentFreeze::create(['team_id' => $other->id, 'reason' => 'Foreign', 'starts_at' => now()]);
    $this->withToken($token->plainTextToken)->postJson("/api/v1/deployment-freezes/{$foreign->uuid}/end")->assertNotFound();
});

it('reports reserved, running and queued build slots', function () {
    $this->server->settings->update(['concurrent_builds' => 2]);
    foreach (['a', 'b', 'c'] as $uuid) {
        queue_application_deployment(Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
        ]), "capacity-{$uuid}");
    }
    ApplicationDeploymentQueue::query()->where('deployment_uuid', 'capacity-a')->first()->update(['started_at' => now()]);

    $capacity = BuildCapacity::forServer($this->server->fresh());

    expect($capacity->toArray())->toMatchArray([
        'concurrent_builds' => 2,
        'running' => 1,
        'reserved' => 1,
        'free_slots' => 0,
        'queued' => 1,
    ]);
});

it('parses deployed images and picks the matching repository digest', function () {
    $imageId = 'sha256:'.str_repeat('a', 64);
    $digest = 'sha256:'.str_repeat('b', 64);
    $containers = DeploymentArtifacts::parseContainers("web|/web-abc|ghcr.io/acme/web:1.2|{$imageId}\n<no value>|/api-x|api-uuid:latest|{$imageId}\ngarbage");
    $images = DeploymentArtifacts::parseImages("{$imageId}|[\"docker.io/library/nginx@sha256:".str_repeat('c', 64)."\",\"ghcr.io/acme/web@{$digest}\"]");

    expect($containers)->toHaveCount(2)
        ->and($containers[0])->toBe(['service' => 'web', 'container' => 'web-abc', 'image' => 'ghcr.io/acme/web:1.2', 'image_id' => $imageId])
        ->and($containers[1]['service'])->toBeNull()
        ->and(DeploymentArtifacts::matchingDigest('ghcr.io/acme/web:1.2', $images[$imageId]))->toBe($digest)
        ->and(DeploymentArtifacts::matchingDigest('api-uuid:latest', []))->toBeNull()
        ->and(DeploymentArtifacts::matchingDigest("ghcr.io/acme/web@{$digest}", []))->toBe($digest)
        ->and(DeploymentArtifacts::primaryDigest(['images' => [['image_id' => $imageId, 'repo_digest' => null]]]))->toBe($imageId);
});
