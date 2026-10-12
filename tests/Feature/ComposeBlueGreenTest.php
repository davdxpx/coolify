<?php

use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\Deployment\ComposeBlueGreen;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Records remote commands instead of running them. Commands that save output get the canned
 * answer whose key appears in the command; a command containing $failOn throws like a failed step.
 */
class RecordingComposeDeploymentJob extends ApplicationDeploymentJob
{
    public array $commands = [];

    public array $logEntries = [];

    public array $answers = [];

    public ?string $failOn = null;

    public function __construct() {}

    public function execute_remote_command(...$commands)
    {
        $savedOutputs = (new ReflectionProperty(ApplicationDeploymentJob::class, 'saved_outputs'))->getValue($this);
        foreach ($commands as $command) {
            $line = is_array($command) ? ($command['command'] ?? $command[0]) : $command;
            $this->commands[] = $line;
            if ($this->failOn !== null && str_contains($line, $this->failOn)) {
                throw new RuntimeException('docker compose up failed: container is unhealthy');
            }
            if (isset($command['save'])) {
                $savedOutputs->put($command['save'], $this->answers[$command['save']] ?? '');
            }
        }
    }
}

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server = $server;
    $this->destination = StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'bg-'.fake()->unique()->word()]);
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
});

function blueGreenApplication(object $test, string $compose, array $settings = [], array $attributes = []): Application
{
    $application = Application::factory()->create([
        'environment_id' => $test->environment->id,
        'destination_id' => $test->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $compose,
        ...$attributes,
    ]);
    $application->settings()->update($settings);

    return $application->fresh();
}

function blueGreenJob(object $test, Application $application): RecordingComposeDeploymentJob
{
    $job = new RecordingComposeDeploymentJob;
    $queue = Mockery::mock(ApplicationDeploymentQueue::class)->makePartial();
    $queue->shouldReceive('addLogEntry')->andReturnUsing(function (string $message) use ($job) {
        $job->logEntries[] = $message;
    });
    foreach ([
        'application' => $application,
        'application_deployment_queue' => $queue,
        'deployment_uuid' => 'deployment-uuid',
        'workdir' => '/artifacts/deployment-uuid',
        'docker_compose_location' => '/docker-compose.yaml',
        'coolify_variables' => '',
        'preserveRepository' => false,
        'pull_request_id' => 0,
        'server' => $test->server,
        'mainServer' => $test->server,
        'saved_outputs' => collect(),
    ] as $property => $value) {
        $reflection = new ReflectionProperty(ApplicationDeploymentJob::class, $property);
        $reflection->setValue($job, $value);
    }

    return $job;
}

/**
 * Runs a closure with the deployment job's private scope.
 */
function blueGreenCall(ApplicationDeploymentJob $job, Closure $callback): mixed
{
    return Closure::bind($callback, $job, ApplicationDeploymentJob::class)();
}

const BLUE_GREEN_WEB = <<<'YAML'
services:
  web:
    build: .
    healthcheck:
      test: ["CMD", "wget", "-qO-", "http://localhost:3000/health"]
YAML;

it('accepts a stateless web service and explains what it cannot handle', function () {
    $service = new ComposeBlueGreen;

    expect($service->analyze(blueGreenApplication($this, BLUE_GREEN_WEB))['eligible'])->toBeTrue();

    $report = $service->analyze(blueGreenApplication($this, <<<'YAML'
services:
  web:
    image: ghcr.io/acme/web:1
    ports: ["8080:3000"]
  db:
    image: postgres:16-alpine
    volumes: [data:/var/lib/postgresql/data]
  worker:
    image: ghcr.io/acme/worker:1
    container_name: worker
volumes:
  data:
YAML));
    $blocking = implode("\n", $report['blocking']);
    expect($report['eligible'])->toBeFalse()
        ->and($blocking)->toContain('web publishes host ports')
        ->and($blocking)->toContain('db runs postgres')
        ->and($blocking)->toContain('worker sets container_name')
        ->and(implode("\n", $report['warnings']))->toContain('service names on the application network resolve to containers of both versions');

    expect($service->analyze(blueGreenApplication($this, BLUE_GREEN_WEB, ['is_raw_compose_deployment_enabled' => true]))['blocking'])->toContain('Raw compose deployments are deployed as written and cannot run twice side by side.')
        ->and($service->analyze(blueGreenApplication($this, BLUE_GREEN_WEB, [], ['docker_compose_custom_start_command' => 'docker compose up -d']))['eligible'])->toBeFalse()
        ->and($service->analyze(blueGreenApplication($this, BLUE_GREEN_WEB, [], ['build_pack' => 'nixpacks']))['eligible'])->toBeFalse();
});

it('recognizes stateful images', function (string $image, ?string $expected) {
    expect(ComposeBlueGreen::statefulImage($image))->toBe($expected);
})->with([
    ['postgres:16-alpine', 'postgres'],
    ['docker.io/library/redis:7', 'redis'],
    ['bitnami/postgresql:16', 'postgresql'],
    ['mongo@sha256:'.str_repeat('a', 64), 'mongo'],
    ['ghcr.io/acme/web:1', null],
    ['redisinsight/redisinsight:latest', null],
    ['nginx:alpine', null],
]);

it('alternates between the two Compose projects', function () {
    expect(ComposeBlueGreen::plan('app1', []))->toBe(['current' => null, 'next' => 'app1'])
        ->and(ComposeBlueGreen::plan('app1', [['name' => 'web-1', 'project' => 'app1', 'service' => 'web']]))->toBe(['current' => 'app1', 'next' => 'app1-green'])
        ->and(ComposeBlueGreen::plan('app1', [['name' => 'web-2', 'project' => 'app1-green', 'service' => 'web']]))->toBe(['current' => 'app1-green', 'next' => 'app1'])
        ->and(ComposeBlueGreen::parseContainers("web-1|app1|web\nbroken\nweb-1|app1|web\n"))->toBe([['name' => 'web-1', 'project' => 'app1', 'service' => 'web']]);
});

it('starts the new version next to the old one, waits for health and then stops the old containers', function () {
    $application = blueGreenApplication($this, BLUE_GREEN_WEB, ['is_compose_blue_green_enabled' => true]);
    $job = blueGreenJob($this, $application);
    $job->answers['compose_blue_green_containers'] = "web-{$application->uuid}-old|{$application->uuid}|web";

    $plan = blueGreenCall($job, fn () => $this->composeBlueGreenPlan());
    blueGreenCall($job, fn () => $this->switch_compose_blue_green($plan));

    $commands = implode("\n", $job->commands);
    $up = collect($job->commands)->first(fn (string $command) => str_contains($command, ' up -d'));
    expect($plan['next'])->toBe("{$application->uuid}-green")
        ->and($up)->toContain("--project-name {$application->uuid}-green")
        ->and($up)->toContain('up -d --wait --wait-timeout 300')
        ->and($commands)->toContain("web-{$application->uuid}-old")
        ->and(collect($job->commands)->search(fn (string $command) => str_contains($command, ' up -d')))
        ->toBeLessThan(collect($job->commands)->search(fn (string $command) => str_contains($command, "web-{$application->uuid}-old")))
        ->and(implode("\n", $job->logEntries))->toContain('the previous version was stopped');

    expect(blueGreenCall($job, fn () => $this->composeProjectName()))->toBe($application->uuid);
});

it('removes a new version that does not become healthy and keeps the old one running', function () {
    $application = blueGreenApplication($this, BLUE_GREEN_WEB, ['is_compose_blue_green_enabled' => true]);
    $job = blueGreenJob($this, $application);
    $job->answers['compose_blue_green_containers'] = "web-{$application->uuid}-green-old|{$application->uuid}-green|web";
    $job->failOn = ' up -d';

    $plan = blueGreenCall($job, fn () => $this->composeBlueGreenPlan());

    expect(fn () => blueGreenCall($job, fn () => $this->switch_compose_blue_green($plan)))
        ->toThrow(DeploymentException::class, 'The previous version is still running.');
    $commands = implode("\n", $job->commands);
    expect($plan['next'])->toBe($application->uuid)
        ->and($commands)->toContain("docker ps -aq --filter 'label=com.docker.compose.project={$application->uuid}' | xargs -r docker rm -f")
        ->and($commands)->not->toContain("web-{$application->uuid}-green-old");
});

it('falls back to the normal deployment when the Compose file does not allow it', function () {
    $application = blueGreenApplication($this, "services:\n  web:\n    image: nginx\n    ports: ['80:80']\n", ['is_compose_blue_green_enabled' => true]);
    $job = blueGreenJob($this, $application);

    expect(blueGreenCall($job, fn () => $this->composeBlueGreenPlan()))->toBeNull()
        ->and(implode("\n", $job->logEntries))->toContain('Zero-downtime deployment is enabled but not possible')
        ->and($job->commands)->toBe([]);

    $application->settings->update(['is_compose_blue_green_enabled' => false]);
    expect(blueGreenCall(blueGreenJob($this, $application->fresh()), fn () => $this->composeBlueGreenPlan()))->toBeNull();
});

it('enables zero-downtime deployment through the API only when the Compose file allows it', function () {
    InstanceSettings::query()->update(['is_api_enabled' => true]);
    $user = User::factory()->create();
    $this->team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $token = $user->createToken('bg', ['read', 'write']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();
    $good = blueGreenApplication($this, BLUE_GREEN_WEB);
    $bad = blueGreenApplication($this, "services:\n  web:\n    image: nginx\n    container_name: web\n");

    $this->withToken($token->plainTextToken)->patchJson("/api/v1/applications/{$good->uuid}/zero-downtime", ['enabled' => true])
        ->assertOk()->assertJsonPath('enabled', true);
    $this->withToken($token->plainTextToken)->patchJson("/api/v1/applications/{$bad->uuid}/zero-downtime", ['enabled' => true])
        ->assertUnprocessable()->assertJsonPath('eligible', false);
    $this->withToken($token->plainTextToken)->getJson("/api/v1/applications/{$bad->uuid}/zero-downtime")
        ->assertOk()->assertJsonPath('enabled', false);

    expect($good->settings->fresh()->is_compose_blue_green_enabled)->toBeTrue();
});

it('shows the check in the advanced settings and refuses to enable an ineligible application', function () {
    $user = User::factory()->create();
    $this->team->members()->attach($user->id, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $this->team]);
    $bad = blueGreenApplication($this, "services:\n  web:\n    image: nginx\n    ports: ['80:80']\n");
    $good = blueGreenApplication($this, BLUE_GREEN_WEB);

    Livewire\Livewire::test(App\Livewire\Project\Application\ZeroDowntime::class, ['application' => $bad])
        ->assertSee('Not possible for this Compose file')
        ->assertSee('web publishes host ports')
        ->set('isEnabled', true)
        ->call('instantSave')
        ->assertDispatched('error')
        ->assertSet('isEnabled', false);
    expect($bad->settings->fresh()->is_compose_blue_green_enabled)->toBeFalse();

    Livewire\Livewire::test(App\Livewire\Project\Application\ZeroDowntime::class, ['application' => $good])
        ->set('isEnabled', true)
        ->call('instantSave')
        ->assertDispatched('success');
    expect($good->settings->fresh()->is_compose_blue_green_enabled)->toBeTrue();
});
