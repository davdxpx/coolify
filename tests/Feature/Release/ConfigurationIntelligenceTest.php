<?php

use App\Actions\Release\CreateRelease;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ReleaseState;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ConfigurationContract;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseGroup;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\Configuration\ConfigurationReport;
use App\Services\Configuration\SharedVariableGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'config-'.fake()->unique()->word()]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->api = Application::factory()->create(['name' => 'api', 'environment_id' => $this->environment->id, 'destination_id' => $destination->id, 'destination_type' => StandaloneDocker::class]);
    $this->web = Application::factory()->create(['name' => 'web', 'environment_id' => $this->environment->id, 'destination_id' => $destination->id, 'destination_type' => StandaloneDocker::class]);

    $this->databaseUrl = SharedEnvironmentVariable::create(['key' => 'DATABASE_URL', 'value' => 'postgres://secret-value', 'type' => 'environment', 'team_id' => $this->team->id, 'project_id' => $this->project->id, 'environment_id' => $this->environment->id]);
    SharedEnvironmentVariable::create(['key' => 'SENTRY_DSN', 'value' => 'https://dsn-secret', 'type' => 'team', 'team_id' => $this->team->id]);

    foreach ([[$this->api, 'DATABASE_URL', '{{environment.DATABASE_URL}}'], [$this->api, 'SENTRY_DSN', '{{ team.SENTRY_DSN }}'], [$this->web, 'DATABASE_URL', '{{environment.DATABASE_URL}}'], [$this->web, 'CACHE_URL', '{{environment.CACHE_URL}}'], [$this->web, 'STRIPE_SECRET_KEY', 'sk_live_very_secret']] as [$application, $key, $value]) {
        EnvironmentVariable::create(['key' => $key, 'value' => $value, 'resourceable_type' => Application::class, 'resourceable_id' => $application->id, 'is_preview' => false]);
    }
});

function configurationTestDeploy(Application $application, $at): void
{
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => (string) $application->id,
        'deployment_uuid' => 'deploy-'.$application->uuid,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'pull_request_id' => 0,
    ]);
    $deployment->forceFill(['created_at' => $at, 'started_at' => $at])->save();
}

it('finds shared variable references in values', function () {
    expect(SharedVariableGraph::referencesIn('postgres://{{ environment.DB_USER }}:{{team.PASSWORD}}@host'))
        ->toBe([['type' => 'environment', 'key' => 'DB_USER'], ['type' => 'team', 'key' => 'PASSWORD']])
        ->and(SharedVariableGraph::referencesIn('{{unknown.KEY}} plain'))->toBe([])
        ->and(SharedVariableGraph::referencesIn(null))->toBe([]);
});

it('maps which resources use a shared variable and which run an older value', function () {
    configurationTestDeploy($this->api, now()->subDay());
    configurationTestDeploy($this->web, now()->addMinute());
    $this->databaseUrl->forceFill(['updated_at' => now()])->save();

    $impact = (new SharedVariableGraph($this->team->id))->impactOf($this->databaseUrl);

    expect($impact->pluck('resource_name')->sort()->values()->all())->toBe(['api', 'web'])
        ->and($impact->firstWhere('resource_name', 'api')['stale'])->toBeTrue()
        ->and($impact->firstWhere('resource_name', 'web')['stale'])->toBeFalse();
});

it('reports drift, missing references, contracts and rotation without any value', function () {
    configurationTestDeploy($this->api, now()->subDay());
    ConfigurationContract::create(['team_id' => $this->team->id, 'application_id' => $this->web->id, 'required_keys' => ['DATABASE_URL', 'CACHE_URL', 'SESSION_SECRET'], 'optional_keys' => ['STRIPE_SECRET_KEY'], 'source' => 'repo:apps/web/.env.example']);
    EnvironmentVariable::query()->where('key', 'STRIPE_SECRET_KEY')->update(['updated_at' => now()->subDays(200)]);

    $report = (new ConfigurationReport)->forEnvironment($this->environment->load('project'));
    $web = collect($report['resources'])->firstWhere('resource_name', 'web');
    $api = collect($report['resources'])->firstWhere('resource_name', 'api');
    $json = json_encode($report);

    expect($web['missing_shared_references'])->toBe([['variable_key' => 'CACHE_URL', 'shared' => 'environment.CACHE_URL']])
        ->and($web['contract']['missing'])->toBe(['SESSION_SECRET'])
        ->and($web['contract']['unresolved'])->toBe(['CACHE_URL'])
        ->and($web['secrets_due_for_rotation'][0]['key'])->toBe('STRIPE_SECRET_KEY')
        ->and($api['stale_shared_references'])->toHaveCount(2)
        ->and($report['summary']['missing_shared_references'])->toBe(1)
        ->and($json)->not->toContain('secret-value')
        ->and($json)->not->toContain('sk_live_very_secret')
        ->and($json)->not->toContain('dsn-secret');
});

it('serves the configuration report, rotation plan and contracts through the API', function () {
    $token = $this->user->createToken('config', ['read', 'write']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();
    $bearer = $token->plainTextToken;

    $this->withToken($bearer)->getJson("/api/v1/configuration/environments/{$this->environment->uuid}")
        ->assertOk()
        ->assertJsonPath('summary.missing_shared_references', 1)
        ->assertDontSee('secret-value');

    $this->withToken($bearer)->getJson("/api/v1/configuration/shared-variables/{$this->databaseUrl->id}/rotation-plan")
        ->assertOk()
        ->assertJsonPath('reference', 'environment.DATABASE_URL')
        ->assertJsonCount(2, 'resources');

    $this->withToken($bearer)->putJson("/api/v1/applications/{$this->api->uuid}/configuration-contract", [
        'required_keys' => ['DATABASE_URL', 'SENTRY_DSN', 'JWT_SECRET'],
        'source' => 'repo:apps/api/.env.example',
        'source_revision' => 'abc123',
    ])->assertOk()->assertJsonPath('check.missing', ['JWT_SECRET']);

    $this->withToken($bearer)->putJson("/api/v1/applications/{$this->api->uuid}/configuration-contract", ['required_keys' => ['bad key']])->assertUnprocessable();
    $this->withToken($bearer)->getJson("/api/v1/applications/{$this->api->uuid}/configuration-contract")->assertOk()->assertJsonPath('source_revision', 'abc123');

    $otherTeam = Team::factory()->create();
    $foreign = SharedEnvironmentVariable::create(['key' => 'X', 'value' => 'y', 'type' => 'team', 'team_id' => $otherTeam->id]);
    $this->withToken($bearer)->getJson("/api/v1/configuration/shared-variables/{$foreign->id}/rotation-plan")->assertNotFound();
});

it('shows the configuration page of an environment', function () {
    $this->actingAs($this->user);

    $this->get(route('project.configuration.index', ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid]))
        ->assertOk()
        ->assertSee('environment.CACHE_URL does not exist')
        ->assertDontSee('secret-value');
});

it('pauses a release whose applications miss required configuration', function () {
    Bus::fake([ApplicationDeploymentJob::class]);
    $this->web->destination->server->settings->update(['is_reachable' => true, 'is_usable' => true]);
    $group = ReleaseGroup::create(['team_id' => $this->team->id, 'environment_id' => $this->environment->id, 'name' => 'Web']);
    $group->members()->create(['resource_type' => Application::class, 'resource_id' => $this->web->id]);

    $release = CreateRelease::run($group, Release::TRIGGER_UI, $this->user);

    expect($release->state)->toBe(ReleaseState::ATTENTION_REQUIRED)
        ->and($release->attention_reason)->toContain('CACHE_URL refers to environment.CACHE_URL, which does not exist')
        ->and(ApplicationDeploymentQueue::count())->toBe(0);
});
