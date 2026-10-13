<?php

use App\Actions\Release\CreateRelease;
use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\DispatchEventDeliveriesJob;
use App\Livewire\EventWebhooks\Index;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\BackupRestoreEvidence;
use App\Models\Environment;
use App\Models\EventDelivery;
use App\Models\EventSubscription;
use App\Models\InstanceSettings;
use App\Models\PlatformEvent;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseGroup;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Services\Events\PlatformEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    Bus::fake([ApplicationDeploymentJob::class]);
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true, 'is_mcp_server_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true]);
    $this->destination = StandaloneDocker::factory()->create(['server_id' => $this->server->id, 'network' => 'events-'.fake()->unique()->word()]);
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
    $token = $this->user->createToken('events', ['read', 'write', 'deploy']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();
    $this->bearer = $token->plainTextToken;
});

function eventsTestSubscription(object $test, array $attributes = []): EventSubscription
{
    return EventSubscription::create([
        'team_id' => $test->team->id,
        'name' => 'Dashboard',
        'url' => 'https://93.184.216.34/hooks/coolify',
        'secret' => 'whsec_test',
        'event_types' => ['*'],
        ...$attributes,
    ]);
}

it('records release and deployment events in a team scoped feed', function () {
    $group = ReleaseGroup::create(['team_id' => $this->team->id, 'environment_id' => $this->environment->id, 'name' => 'Backend']);
    $group->members()->create(['resource_type' => Application::class, 'resource_id' => $this->application->id]);
    Http::fake();

    $release = CreateRelease::run($group, Release::TRIGGER_API, $this->user);
    ApplicationDeploymentQueue::query()->first()->update(['status' => ApplicationDeploymentStatus::FINISHED->value]);
    PlatformEvent::create(['team_id' => Team::factory()->create()->id, 'type' => 'release.started', 'subject_type' => 'release', 'data' => [], 'occurred_at' => now()]);

    $feed = $this->withToken($this->bearer)->getJson('/api/v1/events')->assertOk();
    $types = collect($feed->json('events'))->pluck('type')->all();

    expect($types)->toBe(['release.started', 'deployment.finished', 'release.completed'])
        ->and($feed->json('events.0.correlation_id'))->toBe($release->correlation_id)
        ->and($feed->json('events.1.data.application_uuid'))->toBe($this->application->uuid);

    $this->withToken($this->bearer)->getJson('/api/v1/events?after='.$feed->json('events.1.id'))
        ->assertOk()
        ->assertJsonCount(1, 'events')
        ->assertJsonPath('events.0.type', 'release.completed');
    $this->withToken($this->bearer)->getJson('/api/v1/events?types=deployment.finished')->assertJsonCount(1, 'events');
});

it('delivers events with a verifiable signature', function () {
    $subscription = eventsTestSubscription($this);
    Http::fake(['93.184.216.34/*' => Http::response('ok', 200)]);

    PlatformEvents::record('deployment.failed', $this->team->id, 'deployment', 'dep-1', ['application_uuid' => $this->application->uuid]);

    $delivery = EventDelivery::firstOrFail();
    expect($delivery->state)->toBe(EventDelivery::DELIVERED)
        ->and($delivery->attempts)->toBe(1)
        ->and($subscription->fresh()->last_response_status)->toBe(200);
    Http::assertSent(function (HttpRequest $request) {
        return $request->hasHeader('Coolify-Event', 'deployment.failed')
            && PlatformEvents::verify('whsec_test', $request->body(), $request->header('Coolify-Signature')[0])
            && ! PlatformEvents::verify('wrong-secret', $request->body(), $request->header('Coolify-Signature')[0]);
    });
});

it('retries failed deliveries with backoff, gives up and disables an endpoint that keeps failing', function () {
    $subscription = eventsTestSubscription($this, ['event_types' => ['deployment.failed']]);
    Http::fake(['93.184.216.34/*' => Http::response('nope', 500)]);

    PlatformEvents::record('deployment.finished', $this->team->id, 'deployment', 'ignored');
    PlatformEvents::record('deployment.failed', $this->team->id, 'deployment', 'dep-2');

    $delivery = EventDelivery::firstOrFail();
    expect(EventDelivery::count())->toBe(1)
        ->and($delivery->state)->toBe(EventDelivery::PENDING)
        ->and($delivery->response_status)->toBe(500)
        ->and($delivery->next_attempt_at->greaterThan(now()->addSeconds(20)))->toBeTrue();

    $delivery->update(['attempts' => EventDelivery::MAX_ATTEMPTS - 1, 'next_attempt_at' => now()->subSecond()]);
    $subscription->update(['consecutive_failures' => EventSubscription::DISABLE_AFTER_FAILURES - 1]);
    (new DispatchEventDeliveriesJob)->handle();

    expect($delivery->fresh()->state)->toBe(EventDelivery::FAILED)
        ->and($subscription->fresh()->is_enabled)->toBeFalse()
        ->and($subscription->fresh()->disabled_reason)->toContain('failed deliveries');
});

it('refuses webhook endpoints that are not public HTTPS URLs', function () {
    foreach (['http://93.184.216.34/hook', 'https://10.0.0.5/hook', 'https://127.0.0.1/hook', 'https://169.254.169.254/latest'] as $url) {
        $this->withToken($this->bearer)->postJson('/api/v1/event-subscriptions', ['name' => 'Bad', 'url' => $url])->assertUnprocessable();
    }

    $created = $this->withToken($this->bearer)->postJson('/api/v1/event-subscriptions', ['name' => 'Good', 'url' => 'https://93.184.216.34/hook', 'event_types' => ['release.completed']])
        ->assertCreated();
    expect($created->json('secret'))->toStartWith('whsec_')
        ->and(EventSubscription::count())->toBe(1);

    $this->withToken($this->bearer)->getJson('/api/v1/event-subscriptions')
        ->assertOk()
        ->assertJsonPath('0.url_host', '93.184.216.34')
        ->assertJsonMissingPath('0.secret')
        ->assertJsonMissingPath('0.url');
});

it('does not deliver to a URL that became unsafe after it was saved', function () {
    eventsTestSubscription($this, ['url' => 'https://10.1.2.3/hook']);
    Http::fake();

    PlatformEvents::record('release.completed', $this->team->id, 'release', 'r-1');

    Http::assertNothingSent();
    expect(EventDelivery::first()->last_error)->toContain('private or unsafe');
});

it('sends a test event to one endpoint', function () {
    $subscription = eventsTestSubscription($this, ['is_enabled' => false, 'disabled_reason' => 'Disabled after failures.']);
    Http::fake(['93.184.216.34/*' => Http::response('ok', 204)]);

    $this->withToken($this->bearer)->postJson("/api/v1/event-subscriptions/{$subscription->uuid}/test")->assertStatus(202);

    expect($subscription->fresh()->is_enabled)->toBeTrue();
    Http::assertSent(fn (HttpRequest $request) => $request->hasHeader('Coolify-Event', 'ping'));
    $this->withToken($this->bearer)->getJson("/api/v1/event-subscriptions/{$subscription->uuid}/deliveries")
        ->assertOk()
        ->assertJsonPath('0.event_type', 'ping')
        ->assertJsonPath('0.state', 'delivered');
});

it('serves the platform overview and metrics for the token team', function () {
    queue_application_deployment($this->application, 'overview-1');
    ApplicationDeploymentQueue::query()->update(['status' => ApplicationDeploymentStatus::FINISHED->value, 'started_at' => now()->subMinutes(3), 'finished_at' => now()]);

    $this->withToken($this->bearer)->getJson('/api/v1/platform/overview')
        ->assertOk()
        ->assertJsonPath('servers.0.uuid', $this->server->uuid)
        ->assertJsonPath('servers.0.build_capacity.concurrent_builds', 2)
        ->assertJsonPath('deployments.last_24h.finished', 1)
        ->assertJsonPath('backups.databases', 0);

    $this->withToken($this->bearer)->getJson('/api/v1/platform/metrics?hours=6')
        ->assertOk()
        ->assertJsonPath('window_hours', 6)
        ->assertJsonPath('deployments.total', 1)
        ->assertJsonPath('deployments.execution_seconds.count', 1)
        ->assertJsonPath('deployments.execution_seconds.p50', 180);
});

it('lists rollback candidates with the changes a rollback would keep', function () {
    foreach (['aaa111' => 3, 'bbb222' => 2, 'aaa111x' => 1] as $commit => $hoursAgo) {
        $deployment = ApplicationDeploymentQueue::create([
            'application_id' => (string) $this->application->id,
            'deployment_uuid' => "rollback-{$commit}",
            'commit' => $commit,
            'pull_request_id' => 0,
            'status' => ApplicationDeploymentStatus::FINISHED->value,
            'artifacts' => ['images' => [['service' => null, 'container' => 'api', 'image' => "{$this->application->uuid}:{$commit}", 'image_id' => 'sha256:'.str_repeat('a', 64), 'repo_digest' => null]]],
        ]);
        $deployment->forceFill(['created_at' => now()->subHours($hoursAgo), 'started_at' => now()->subHours($hoursAgo)])->save();
    }

    $response = $this->withToken($this->bearer)->getJson("/api/v1/applications/{$this->application->uuid}/rollback-candidates")->assertOk();

    expect($response->json('current.commit'))->toBe('aaa111x')
        ->and(collect($response->json('candidates'))->pluck('commit')->all())->toBe(['bbb222', 'aaa111'])
        ->and($response->json('candidates.0.images.0.image'))->toBe("{$this->application->uuid}:bbb222")
        ->and($response->json('candidates.0.image_available'))->toBeNull()
        ->and($response->json('notes.0'))->toContain('current environment variables');
});

it('records restore tests and reports them with backup freshness', function () {
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

    $this->withToken($this->bearer)->postJson("/api/v1/databases/{$database->uuid}/restore-evidence", ['result' => 'passed', 'notes' => 'Restored on the rehearsal VM.'])
        ->assertCreated()
        ->assertJsonPath('result', 'passed');
    $this->withToken($this->bearer)->postJson("/api/v1/databases/{$database->uuid}/restore-evidence", ['result' => 'maybe'])->assertUnprocessable();

    $this->withToken($this->bearer)->getJson("/api/v1/backups/freshness?environment_uuid={$this->environment->uuid}")
        ->assertOk()
        ->assertJsonPath('0.database_name', 'main-db')
        ->assertJsonPath('0.configured', false)
        ->assertJsonPath('0.last_restore_result', 'passed');
    expect(BackupRestoreEvidence::count())->toBe(1);
});

it('offers releases, the overview and the configuration report as MCP tools', function () {
    $group = ReleaseGroup::create(['team_id' => $this->team->id, 'environment_id' => $this->environment->id, 'name' => 'Backend']);
    $group->members()->create(['resource_type' => Application::class, 'resource_id' => $this->application->id]);
    $release = CreateRelease::run($group, Release::TRIGGER_API, $this->user);
    $call = function (string $tool, array $arguments = []) {
        auth()->forgetGuards();
        $response = $this->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer '.$this->bearer])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]]);

        return json_decode((string) $response->json('result.content.0.text'), true);
    };

    expect($call('list_releases')['data'][0]['uuid'])->toBe($group->uuid)
        ->and($call('get_release', ['release_uuid' => $release->uuid])['data']['state'])->toBe('running')
        ->and($call('get_platform_overview', ['metrics_hours' => 24])['data'])->toHaveKeys(['servers', 'releases', 'metrics'])
        ->and($call('get_configuration_report', ['environment_uuid' => $this->environment->uuid])['data']['summary']['resources'])->toBe(1);
});

it('manages event webhooks on the page and shows the secret once', function () {
    $this->actingAs($this->user);

    Livewire\Livewire::test(Index::class)
        ->set('name', 'Dashboard')
        ->set('url', 'https://93.184.216.34/hook')
        ->set('eventTypes', ['release.completed', 'release.failed'])
        ->call('create')
        ->assertSet('createdSecret', fn (?string $secret) => str_starts_with((string) $secret, 'whsec_'))
        ->assertSee('Copy the signing secret now');

    expect(EventSubscription::firstOrFail()->event_types)->toBe(['release.completed', 'release.failed']);

    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);
    Livewire\Livewire::test(Index::class)
        ->assertDontSee('New event webhook')
        ->set('name', 'Sneaky')
        ->set('url', 'https://93.184.216.34/other')
        ->call('create');
    expect(EventSubscription::count())->toBe(1);
});
