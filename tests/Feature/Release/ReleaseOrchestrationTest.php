<?php

use App\Actions\Release\CancelRelease;
use App\Actions\Release\CreateRelease;
use App\Actions\Release\ResumeRelease;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ReleaseFailurePolicy;
use App\Enums\ReleaseMemberState;
use App\Enums\ReleaseState;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\ProcessReleaseJob;
use App\Jobs\ReconcileReleasesJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseGroup;
use App\Models\ReleaseGroupMember;
use App\Models\ReleaseMember;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\Release\ReleaseGraph;
use App\Services\Release\ReleaseOrchestrator;
use App\Services\Release\ReleaseRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    Bus::fake([ApplicationDeploymentJob::class]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'admin']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true, 'concurrent_builds' => 5]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'release-network-'.fake()->unique()->word(),
    ]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function releaseTestApplication(object $test, string $name, array $attributes = []): Application
{
    return Application::factory()->create([
        'name' => $name,
        'environment_id' => $test->environment->id,
        'destination_id' => $test->destination->id,
        'destination_type' => StandaloneDocker::class,
        'git_commit_sha' => 'HEAD',
        'status' => 'running:healthy',
        ...$attributes,
    ]);
}

/**
 * @param  array<string, array{phase?: int, depends_on?: list<string>, is_required?: bool}>  $members  keyed by application name
 * @return array{0: ReleaseGroup, 1: array<string, ReleaseGroupMember>, 2: array<string, Application>}
 */
function releaseTestGroup(object $test, array $members, array $settings = []): array
{
    $group = ReleaseGroup::create([
        'team_id' => $test->team->id,
        'environment_id' => $test->environment->id,
        'name' => 'Web stack',
        'max_parallel' => 5,
        ...$settings,
    ]);
    $groupMembers = [];
    $applications = [];
    foreach ($members as $name => $options) {
        $applications[$name] = releaseTestApplication($test, $name);
        $groupMembers[$name] = $group->members()->create([
            'resource_type' => Application::class,
            'resource_id' => $applications[$name]->id,
            'phase' => $options['phase'] ?? 1,
            'is_required' => $options['is_required'] ?? true,
        ]);
    }
    foreach ($members as $name => $options) {
        $dependencies = array_map(fn (string $dependency) => $groupMembers[$dependency]->uuid, $options['depends_on'] ?? []);
        $groupMembers[$name]->update(['depends_on' => $dependencies]);
    }

    return [$group->refresh(), $groupMembers, $applications];
}

function releaseTestMember(Release $release, string $name): ReleaseMember
{
    return $release->members()->where('resource_name', $name)->firstOrFail();
}

function releaseTestFinishDeployment(Release $release, string $name, ApplicationDeploymentStatus $status = ApplicationDeploymentStatus::FINISHED): void
{
    $member = releaseTestMember($release, $name);
    expect($member->deployment_uuid)->not->toBeNull();
    ApplicationDeploymentQueue::query()->where('deployment_uuid', $member->deployment_uuid)->firstOrFail()->update(['status' => $status->value]);
}

it('validates dependencies within a group', function () {
    $graph = new ReleaseGraph([
        'a' => ['name' => 'api', 'phase' => 1, 'depends_on' => ['c']],
        'b' => ['name' => 'web', 'phase' => 1, 'depends_on' => ['a']],
        'c' => ['name' => 'worker', 'phase' => 1, 'depends_on' => ['b']],
        'd' => ['name' => 'migrate', 'phase' => 2, 'depends_on' => []],
        'e' => ['name' => 'edge', 'phase' => 1, 'depends_on' => ['d', 'missing', 'e']],
    ]);
    $errors = implode("\n", $graph->errors());

    expect($errors)->toContain('Dependencies form a cycle')
        ->and($errors)->toContain('edge runs in phase 1 but depends on migrate in the later phase 2.')
        ->and($errors)->toContain('edge depends on a resource that is not part of this group.')
        ->and($errors)->toContain('edge cannot depend on itself.')
        ->and((new ReleaseGraph(['a' => ['name' => 'api', 'phase' => 1, 'depends_on' => []], 'b' => ['name' => 'web', 'phase' => 2, 'depends_on' => ['a']]]))->errors())->toBe([])
        ->and($graph->dependentsOf('d'))->toBe(['e']);
});

it('deploys phases in order, respects dependencies and the parallel limit, and completes', function () {
    [$group] = releaseTestGroup($this, [
        'database-migrate' => ['phase' => 1],
        'api' => ['phase' => 2],
        'web' => ['phase' => 2, 'depends_on' => ['api']],
        'worker' => ['phase' => 2],
    ], ['max_parallel' => 1]);

    $release = CreateRelease::run($group, Release::TRIGGER_UI, $this->user, 'main');

    expect($release->state)->toBe(ReleaseState::RUNNING)
        ->and(ApplicationDeploymentQueue::count())->toBe(1)
        ->and(releaseTestMember($release, 'database-migrate')->state)->toBe(ReleaseMemberState::DEPLOYING)
        ->and(releaseTestMember($release, 'api')->note)->toBe('Waiting for phase 1 to finish.')
        ->and(ApplicationDeploymentQueue::first()->commit)->toBe('main');

    releaseTestFinishDeployment($release, 'database-migrate');
    expect(releaseTestMember($release, 'database-migrate')->state)->toBe(ReleaseMemberState::SUCCEEDED)
        ->and(releaseTestMember($release, 'api')->state)->toBe(ReleaseMemberState::DEPLOYING)
        ->and(releaseTestMember($release, 'web')->note)->toBe('Waiting for api.')
        ->and(releaseTestMember($release, 'worker')->note)->toContain('Waiting for a free slot');

    releaseTestFinishDeployment($release, 'api');
    expect(releaseTestMember($release, 'web')->state)->toBe(ReleaseMemberState::DEPLOYING)
        ->and(releaseTestMember($release, 'worker')->state)->toBe(ReleaseMemberState::PENDING);

    releaseTestFinishDeployment($release, 'web');
    releaseTestFinishDeployment($release, 'worker');

    $release->refresh();
    expect($release->state)->toBe(ReleaseState::COMPLETED)
        ->and($release->finished_at)->not->toBeNull()
        ->and($release->summary['succeeded'])->toBe(4)
        ->and(ApplicationDeploymentQueue::count())->toBe(4);
});

it('stops starting resources after a required failure with the stop policy', function () {
    [$group] = releaseTestGroup($this, ['api' => ['phase' => 1], 'web' => ['phase' => 2]]);
    $release = CreateRelease::run($group, Release::TRIGGER_UI, $this->user);

    releaseTestFinishDeployment($release, 'api', ApplicationDeploymentStatus::FAILED);

    $release->refresh();
    expect($release->state)->toBe(ReleaseState::FAILED)
        ->and(releaseTestMember($release, 'web')->state)->toBe(ReleaseMemberState::SKIPPED)
        ->and(releaseTestMember($release, 'web')->failure_reason)->toBe('Skipped because the release stopped after api failed.')
        ->and($release->failure_summary)->toContain('api: The deployment failed.')
        ->and(ApplicationDeploymentQueue::count())->toBe(1);
});

it('continues with independent resources and skips dependents', function () {
    [$group] = releaseTestGroup($this, [
        'api' => ['phase' => 1],
        'cache-warmer' => ['phase' => 1],
        'web' => ['phase' => 2, 'depends_on' => ['api']],
        'docs' => ['phase' => 2],
    ], ['failure_policy' => ReleaseFailurePolicy::CONTINUE_INDEPENDENT]);
    $release = CreateRelease::run($group, Release::TRIGGER_UI, $this->user);

    releaseTestFinishDeployment($release, 'api', ApplicationDeploymentStatus::FAILED);
    releaseTestFinishDeployment($release, 'cache-warmer');

    expect(releaseTestMember($release, 'web')->state)->toBe(ReleaseMemberState::SKIPPED)
        ->and(releaseTestMember($release, 'docs')->state)->toBe(ReleaseMemberState::DEPLOYING);

    releaseTestFinishDeployment($release, 'docs');
    expect($release->refresh()->state)->toBe(ReleaseState::PARTIALLY_FAILED);
});

it('pauses with the manual policy and retries the failed resource with a new deployment', function () {
    [$group] = releaseTestGroup($this, ['api' => ['phase' => 1], 'web' => ['phase' => 2, 'depends_on' => ['api']]], ['failure_policy' => ReleaseFailurePolicy::MANUAL]);
    $release = CreateRelease::run($group, Release::TRIGGER_UI, $this->user);
    $firstDeployment = releaseTestMember($release, 'api')->deployment_uuid;

    releaseTestFinishDeployment($release, 'api', ApplicationDeploymentStatus::FAILED);
    $release->refresh();
    expect($release->state)->toBe(ReleaseState::ATTENTION_REQUIRED)
        ->and($release->attention_reason)->toContain('api did not succeed')
        ->and(releaseTestMember($release, 'web')->state)->toBe(ReleaseMemberState::PENDING);

    ResumeRelease::run($release, ResumeRelease::RETRY, $this->user);
    $api = releaseTestMember($release, 'api');
    expect($api->state)->toBe(ReleaseMemberState::DEPLOYING)
        ->and($api->attempts)->toBe(2)
        ->and($api->deployment_uuid)->not->toBe($firstDeployment);

    releaseTestFinishDeployment($release, 'api');
    releaseTestFinishDeployment($release, 'web');
    expect($release->refresh()->state)->toBe(ReleaseState::COMPLETED);
});

it('continues without the failed resource when the operator accepts the failure', function () {
    [$group] = releaseTestGroup($this, ['api' => ['phase' => 1], 'web' => ['phase' => 2, 'depends_on' => ['api']], 'docs' => ['phase' => 2]], ['failure_policy' => ReleaseFailurePolicy::MANUAL]);
    $release = CreateRelease::run($group, Release::TRIGGER_UI, $this->user);
    releaseTestFinishDeployment($release, 'api', ApplicationDeploymentStatus::FAILED);

    ResumeRelease::run($release->refresh(), ResumeRelease::CONTINUE, $this->user);
    expect(releaseTestMember($release, 'web')->state)->toBe(ReleaseMemberState::SKIPPED)
        ->and(releaseTestMember($release, 'docs')->state)->toBe(ReleaseMemberState::DEPLOYING);

    releaseTestFinishDeployment($release, 'docs');
    expect($release->refresh()->state)->toBe(ReleaseState::PARTIALLY_FAILED);
});

it('does not block the release on an optional resource', function () {
    [$group] = releaseTestGroup($this, ['api' => ['phase' => 1], 'preview-docs' => ['phase' => 1, 'is_required' => false]]);
    $release = CreateRelease::run($group, Release::TRIGGER_UI, $this->user);

    releaseTestFinishDeployment($release, 'preview-docs', ApplicationDeploymentStatus::FAILED);
    releaseTestFinishDeployment($release, 'api');

    expect($release->refresh()->state)->toBe(ReleaseState::COMPLETED)
        ->and($release->failure_summary)->toContain('preview-docs');
});

it('returns the same release for a repeated idempotency key and refuses a second concurrent release', function () {
    [$group] = releaseTestGroup($this, ['api' => []]);

    $first = CreateRelease::run($group, Release::TRIGGER_API, $this->user, null, null, 'deploy-42');
    $again = CreateRelease::run($group, Release::TRIGGER_API, $this->user, null, null, 'deploy-42');

    expect($again->id)->toBe($first->id)
        ->and(Release::count())->toBe(1)
        ->and(ApplicationDeploymentQueue::count())->toBe(1)
        ->and(fn () => CreateRelease::run($group, Release::TRIGGER_API, $this->user, null, null, 'deploy-43'))
        ->toThrow(ReleaseRefused::class, 'still running');
});

it('refuses releases for members, other teams and invalid commits', function () {
    [$group] = releaseTestGroup($this, ['api' => []]);
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $stranger = User::factory()->create();
    Team::factory()->create()->members()->attach($stranger->id, ['role' => 'owner']);

    expect(fn () => CreateRelease::run($group, Release::TRIGGER_UI, $member))->toThrow(ReleaseRefused::class)
        ->and(fn () => CreateRelease::run($group, Release::TRIGGER_UI, $stranger))->toThrow(ReleaseRefused::class)
        ->and(fn () => CreateRelease::run($group, Release::TRIGGER_UI, $this->user, '--upload-pack=evil'))->toThrow(ReleaseRefused::class, 'must not start with a hyphen')
        ->and(Release::count())->toBe(0);
});

it('requeues with the same deployment id after a crash before queueing and never queues twice', function () {
    [$group] = releaseTestGroup($this, ['api' => [], 'web' => []]);
    $release = CreateRelease::run($group, Release::TRIGGER_UI, $this->user);

    $api = releaseTestMember($release, 'api');
    $web = releaseTestMember($release, 'web');
    $apiDeployment = $api->deployment_uuid;
    ApplicationDeploymentQueue::query()->where('deployment_uuid', $apiDeployment)->delete();
    $api->update(['state' => ReleaseMemberState::DISPATCHING]);
    $web->update(['state' => ReleaseMemberState::DISPATCHING]);

    app(ReleaseOrchestrator::class)->tick($release);
    app(ReleaseOrchestrator::class)->tick($release);

    expect(ApplicationDeploymentQueue::query()->where('deployment_uuid', $apiDeployment)->count())->toBe(1)
        ->and(ApplicationDeploymentQueue::query()->where('deployment_uuid', $web->deployment_uuid)->count())->toBe(1)
        ->and(ApplicationDeploymentQueue::count())->toBe(2)
        ->and(releaseTestMember($release, 'api')->state)->toBe(ReleaseMemberState::DEPLOYING)
        ->and(releaseTestMember($release, 'api')->attempts)->toBe(1);
});

it('waits for the deployment queue instead of bypassing the server limit', function () {
    $this->server->settings->update(['concurrent_builds' => 1]);
    [$group] = releaseTestGroup($this, ['api' => [], 'web' => []]);
    $release = CreateRelease::run($group, Release::TRIGGER_UI, $this->user);

    expect(ApplicationDeploymentQueue::query()->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)->count())->toBe(1)
        ->and(ApplicationDeploymentQueue::query()->where('status', ApplicationDeploymentStatus::QUEUED->value)->count())->toBe(1)
        ->and($release->members()->get()->pluck('state')->map->value->sort()->values()->all())->toBe(['deploying', 'queued']);
});

it('cancels waiting work but lets running deployments finish', function () {
    $this->server->settings->update(['concurrent_builds' => 1]);
    [$group] = releaseTestGroup($this, ['api' => ['phase' => 1], 'web' => ['phase' => 1], 'docs' => ['phase' => 2]]);
    $release = CreateRelease::run($group, Release::TRIGGER_UI, $this->user);

    CancelRelease::run($release, $this->user);

    $release->refresh();
    $running = $release->members()->where('state', ReleaseMemberState::DEPLOYING->value)->firstOrFail();
    $queuedName = $running->resource_name === 'api' ? 'web' : 'api';
    expect($release->state)->toBe(ReleaseState::CANCELLED)
        ->and(releaseTestMember($release, 'docs')->state)->toBe(ReleaseMemberState::CANCELLED)
        ->and(releaseTestMember($release, $queuedName)->state)->toBe(ReleaseMemberState::CANCELLED)
        ->and(ApplicationDeploymentQueue::query()->where('deployment_uuid', releaseTestMember($release, $queuedName)->deployment_uuid)->value('status'))->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value)
        ->and(ApplicationDeploymentQueue::query()->where('deployment_uuid', $running->deployment_uuid)->value('status'))->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);

    releaseTestFinishDeployment($release, $running->resource_name);
    expect(releaseTestMember($release, $running->resource_name)->state)->toBe(ReleaseMemberState::SUCCEEDED)
        ->and($release->refresh()->state)->toBe(ReleaseState::CANCELLED)
        ->and(fn () => CancelRelease::run($release, $this->user))->toThrow(ReleaseRefused::class, 'already ended');
});

it('waits for a healthy application and fails after the verification timeout', function () {
    [$group, , $applications] = releaseTestGroup($this, ['api' => []], ['verification_timeout_seconds' => 120]);
    $applications['api']->update(['status' => 'exited:unhealthy']);
    $release = CreateRelease::run($group, Release::TRIGGER_UI, $this->user);

    releaseTestFinishDeployment($release, 'api');
    $member = releaseTestMember($release, 'api');
    expect($member->state)->toBe(ReleaseMemberState::VERIFYING)
        ->and($member->verification['container_status'])->toBe('exited:unhealthy');

    $member->update(['deployed_at' => now()->subMinutes(5)]);
    app(ReleaseOrchestrator::class)->tick($release);

    expect(releaseTestMember($release, 'api')->state)->toBe(ReleaseMemberState::FAILED)
        ->and(releaseTestMember($release, 'api')->failure_reason)->toContain('within 120 seconds')
        ->and($release->refresh()->state)->toBe(ReleaseState::FAILED);
});

it('pauses before deploying when a server is not usable and continues after the check passes', function () {
    $this->server->settings->update(['is_reachable' => false]);
    [$group] = releaseTestGroup($this, ['api' => []]);
    $release = CreateRelease::run($group, Release::TRIGGER_UI, $this->user);

    expect($release->state)->toBe(ReleaseState::ATTENTION_REQUIRED)
        ->and($release->attention_reason)->toContain('Not reachable or not usable')
        ->and(ApplicationDeploymentQueue::count())->toBe(0);

    $this->server->settings->update(['is_reachable' => true]);
    ResumeRelease::run($release, ResumeRelease::RETRY, $this->user);

    expect($release->refresh()->state)->toBe(ReleaseState::RUNNING)
        ->and(ApplicationDeploymentQueue::count())->toBe(1);
});

it('reconciles releases whose deployments changed without a model event', function () {
    [$group] = releaseTestGroup($this, ['api' => []]);
    $release = CreateRelease::run($group, Release::TRIGGER_UI, $this->user);
    ApplicationDeploymentQueue::query()->where('deployment_uuid', releaseTestMember($release, 'api')->deployment_uuid)->update(['status' => ApplicationDeploymentStatus::FINISHED->value]);

    expect($release->refresh()->state)->toBe(ReleaseState::RUNNING);
    (new ReconcileReleasesJob)->handle();

    expect($release->refresh()->state)->toBe(ReleaseState::COMPLETED);
});

it('does not run a duplicate tick while another one holds the release', function () {
    [$group] = releaseTestGroup($this, ['api' => []]);
    $group->update(['is_enabled' => true]);
    Bus::fake([ApplicationDeploymentJob::class, ProcessReleaseJob::class]);
    $release = CreateRelease::run($group, Release::TRIGGER_UI, $this->user);
    expect($release->state)->toBe(ReleaseState::CREATED);

    $lock = ReleaseOrchestrator::lockFor($release);
    $lock->get();
    app(ReleaseOrchestrator::class)->tick($release);
    expect($release->refresh()->state)->toBe(ReleaseState::CREATED);

    $lock->release();
    app(ReleaseOrchestrator::class)->tick($release);
    expect($release->refresh()->state)->toBe(ReleaseState::RUNNING);
});
