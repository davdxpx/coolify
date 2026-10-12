<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\DeploymentPhase;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\RecoverStalledDeploymentsJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    Bus::fake([ApplicationDeploymentJob::class]);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'phase-network-'.fake()->unique()->word(),
    ]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function deploymentPhaseTestApplication(int $environmentId, int $destinationId): Application
{
    return Application::factory()->create([
        'environment_id' => $environmentId,
        'destination_id' => $destinationId,
        'destination_type' => StandaloneDocker::class,
        'git_commit_sha' => 'HEAD',
    ]);
}

it('separates reserved slots from waiting deployments while keeping the in_progress reservation', function () {
    $this->server->settings->update(['concurrent_builds' => 2]);
    $applications = collect(range(1, 4))->map(fn () => deploymentPhaseTestApplication($this->environment->id, $this->destination->id));

    $applications->each(fn (Application $application, int $index) => queue_application_deployment($application, "phase-{$index}"));

    $rows = ApplicationDeploymentQueue::query()->orderBy('id')->get();
    expect($rows->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value))->toHaveCount(2)
        ->and($rows->where('status', ApplicationDeploymentStatus::QUEUED->value))->toHaveCount(2);

    foreach ($rows->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value) as $row) {
        expect($row->phase)->toBe(DeploymentPhase::DISPATCHED)
            ->and($row->admitted_at)->not->toBeNull()
            ->and($row->dispatched_at)->not->toBeNull()
            ->and($row->started_at)->toBeNull()
            ->and(array_column($row->phase_history, 'phase'))->toBe(['queued', 'admitted', 'dispatched'])
            ->and($row->displayState()['label'])->toBe('Waiting for worker');
    }

    foreach ($rows->where('status', ApplicationDeploymentStatus::QUEUED->value) as $row) {
        expect($row->phase)->toBe(DeploymentPhase::QUEUED)
            ->and($row->admitted_at)->toBeNull()
            ->and($row->displayState()['label'])->toBe('Queued');
    }

    Bus::assertDispatchedTimes(ApplicationDeploymentJob::class, 2);
});

it('records admission when a queued deployment starts later', function () {
    $application = deploymentPhaseTestApplication($this->environment->id, $this->destination->id);
    $this->server->settings->update(['concurrent_builds' => 0]);
    queue_application_deployment($application, 'later-start');
    $deployment = ApplicationDeploymentQueue::query()->where('deployment_uuid', 'later-start')->firstOrFail();
    expect($deployment->phase)->toBe(DeploymentPhase::QUEUED);

    $this->server->settings->update(['concurrent_builds' => 1]);
    expect(start_queued_deployment($deployment))->toBeTrue();

    $deployment->refresh();
    expect($deployment->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($deployment->phase)->toBe(DeploymentPhase::DISPATCHED)
        ->and($deployment->admitted_at)->not->toBeNull();
});

it('never moves a running deployment back to dispatched or out of a terminal phase', function () {
    $application = deploymentPhaseTestApplication($this->environment->id, $this->destination->id);
    queue_application_deployment($application, 'ordering');
    $deployment = ApplicationDeploymentQueue::query()->where('deployment_uuid', 'ordering')->firstOrFail();

    $deployment->recordPhase(DeploymentPhase::BUILDING, ['started_at' => now()]);
    expect($deployment->recordPhase(DeploymentPhase::DISPATCHED, ['dispatched_at' => now()], [DeploymentPhase::ADMITTED]))->toBeFalse();

    $deployment->refresh();
    expect($deployment->phase)->toBe(DeploymentPhase::BUILDING);

    $startedAt = $deployment->started_at;
    $deployment->recordPhase(DeploymentPhase::FINISHED, ['started_at' => now()->addHour()]);
    $deployment->recordPhase(DeploymentPhase::FAILED);

    $deployment->refresh();
    expect($deployment->phase)->toBe(DeploymentPhase::FINISHED)
        ->and($deployment->started_at->equalTo($startedAt))->toBeTrue();
});

it('shows legacy deployments without a phase as in progress', function () {
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => '1',
        'deployment_uuid' => 'legacy-row',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]);

    expect($deployment->displayState())->toMatchArray(['label' => 'In progress', 'type' => 'warning', 'phase' => null]);
});

it('uses the stored status for cancelled deployments whatever the last phase was', function () {
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => '1',
        'deployment_uuid' => 'cancelled-row',
        'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
        'phase' => DeploymentPhase::BUILDING->value,
    ]);

    expect($deployment->displayState()['label'])->toBe('Cancelled');
});

it('releases the slot of a deployment whose worker never started and starts the next one', function () {
    $this->server->settings->update(['concurrent_builds' => 1, 'dynamic_timeout' => 600]);
    $first = deploymentPhaseTestApplication($this->environment->id, $this->destination->id);
    $second = deploymentPhaseTestApplication($this->environment->id, $this->destination->id);
    queue_application_deployment($first, 'lost-job');
    queue_application_deployment($second, 'waiting-job');

    ApplicationDeploymentQueue::query()->where('deployment_uuid', 'lost-job')->update(['admitted_at' => now()->subMinutes(20)]);

    (new RecoverStalledDeploymentsJob)->handle();

    $lost = ApplicationDeploymentQueue::query()->where('deployment_uuid', 'lost-job')->firstOrFail();
    $waiting = ApplicationDeploymentQueue::query()->where('deployment_uuid', 'waiting-job')->firstOrFail();
    expect($lost->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($lost->phase)->toBe(DeploymentPhase::FAILED)
        ->and($waiting->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
});

it('leaves recently admitted, started and legacy deployments alone', function () {
    $this->server->settings->update(['concurrent_builds' => 5, 'dynamic_timeout' => 3600]);
    $fresh = deploymentPhaseTestApplication($this->environment->id, $this->destination->id);
    $started = deploymentPhaseTestApplication($this->environment->id, $this->destination->id);
    queue_application_deployment($fresh, 'fresh-job');
    queue_application_deployment($started, 'started-job');
    ApplicationDeploymentQueue::query()->where('deployment_uuid', 'fresh-job')->update(['admitted_at' => now()->subMinutes(30)]);
    ApplicationDeploymentQueue::query()->where('deployment_uuid', 'started-job')->update(['admitted_at' => now()->subHours(3), 'started_at' => now()->subHours(3)]);
    ApplicationDeploymentQueue::create(['application_id' => (string) $fresh->id, 'deployment_uuid' => 'legacy-job', 'server_id' => $this->server->id, 'status' => ApplicationDeploymentStatus::IN_PROGRESS->value]);

    (new RecoverStalledDeploymentsJob)->handle();

    expect(ApplicationDeploymentQueue::query()->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)->pluck('deployment_uuid')->sort()->values()->all())
        ->toBe(['fresh-job', 'legacy-job', 'started-job']);
});

it('does not start a deployment whose slot was already released', function () {
    $application = deploymentPhaseTestApplication($this->environment->id, $this->destination->id);
    queue_application_deployment($application, 'released-job');
    $deployment = ApplicationDeploymentQueue::query()->where('deployment_uuid', 'released-job')->firstOrFail();
    $deployment->update(['status' => ApplicationDeploymentStatus::FAILED->value]);

    (new ApplicationDeploymentJob($deployment->id))->handle();

    $deployment->refresh();
    expect($deployment->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($deployment->started_at)->toBeNull();
});

it('reports queue wait and execution time from the recorded timestamps', function () {
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => '1',
        'deployment_uuid' => 'timing-row',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);
    $deployment->forceFill([
        'created_at' => now()->subMinutes(10),
        'admitted_at' => now()->subMinutes(7),
        'started_at' => now()->subMinutes(6),
        'finished_at' => now()->subMinutes(1),
    ])->save();

    expect($deployment->queueWaitSeconds())->toBe(180)
        ->and($deployment->executionSeconds())->toBe(300);
});
