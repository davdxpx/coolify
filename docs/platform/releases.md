# Release groups

A release group lists applications of one environment that are deployed together: in which order, which ones must succeed, and what happens when one fails. Starting the group creates a **release**, a durable record of one run.

Open it from an environment: **Releases** next to *Shared variables*.

## Configuration

| Setting | Meaning |
| --- | --- |
| Phase | Phases run one after another. A resource in phase 2 starts only when every resource of phase 1 has ended. |
| Waits for | Dependencies inside a phase or on earlier phases. A resource starts only after the named resources **succeeded**. If one of them does not succeed, the resource is skipped. Dependencies on a later phase and cycles are rejected when you save. |
| Required | A failed required resource triggers the failure policy. An optional resource may fail without stopping the release; resources that wait for it are still skipped. |
| Rebuild | Deploys with *force rebuild* (no build cache, no reuse of an existing deployment of the same commit). |
| Enabled | Disabled resources are not part of new releases. |
| When a required resource fails | **Stop the release**: nothing new starts, running deployments finish. **Continue with independent resources**: resources that (directly or indirectly) wait for the failed one are skipped, all others continue. **Pause and wait for a decision**: the release pauses until someone retries the failed resources, continues without them, or cancels. |
| Resources deployed at the same time | Upper limit for the release. Each server's own limit for concurrent builds still applies, because every deployment goes through the normal deployment queue. |
| Wait until each application runs and is healthy | After the deployment finished, the release waits until the application reports a running state that is not unhealthy (Coolify's container status), up to the verification timeout. For applications with additional servers it also waits for every server's deployment. |

## Starting a release

**Start release** takes an optional commit, branch or tag. It is used for every application that builds from Git; applications that run a prebuilt image keep their configured tag. Without a commit every application deploys its configured commit.

Only one release of a group runs at a time. Starting needs admin rights in the team and the deploy permission on every application of the group.

Before the first deployment the release runs its checks: every resource still exists in the environment, phases and dependencies are consistent, every target server is reachable and usable, no deployment freeze is active, and, if the group asks for it, the release window and database backup age are met ([deployment-controls.md](deployment-controls.md)). Warnings (another running release with the same applications, nearly full deployment queues) are shown but do not block. A failed check pauses the release with the reason; **Check again** re-runs the checks, and failed checks that allow it can be overridden with an audited reason.

## What happens while it runs

The orchestrator advances a release in small steps. Each step reads the stored state, so a restart, a lost worker or two steps at the same time cannot start a deployment twice:

- The deployment id is stored on the release before the deployment is queued. If Coolify stops between both steps, the next step queues the same id again; if the deployment already exists, it is adopted.
- If another deployment of the same commit is already queued for an application (for example from a webhook), the release joins it instead of starting a second one.
- If a server's deployment queue is full, the resource waits and the release retries.

A release advances immediately when one of its deployments changes its status and at least once a minute through the scheduler (`ReconcileReleasesJob`), which also covers status changes that happened without a model event.

States of a release: *Created*, *Checking*, *Running*, *Needs attention*, *Completed*, *Partially failed* (at least one resource succeeded, a required one did not), *Failed*, *Cancelled*.

## Cancelling

**Cancel release** starts nothing new and cancels deployments that still wait in the queue. Deployments that already run are not interrupted; the release keeps recording their result.

## Run again

A finished release can be started again with the same commit. The new release uses the current resources and settings of the group and points to the previous run.

## API

All endpoints are team-scoped and documented in the OpenAPI specification under *Releases*.

| Method | Path | Ability |
| --- | --- | --- |
| GET | `/api/v1/release-groups` | read |
| GET | `/api/v1/release-groups/{uuid}` | read |
| GET | `/api/v1/release-groups/{uuid}/releases` | read |
| POST | `/api/v1/release-groups/{uuid}/releases` | deploy |
| GET | `/api/v1/releases/{uuid}` | read |
| POST | `/api/v1/releases/{uuid}/cancel` | deploy |
| POST | `/api/v1/releases/{uuid}/resume` (`mode`: `retry`, `continue` or `override` with `acknowledged_checks` and `reason`) | deploy |
| GET | `/api/v1/servers/{uuid}/build-capacity` | read |
| GET, POST | `/api/v1/deployment-freezes`, `/api/v1/deployment-freezes/{uuid}/end` | read, write |

`POST /release-groups/{uuid}/releases` accepts an `Idempotency-Key` header. A repeated request with the same key returns the release of the first request (HTTP 200 instead of 201) and starts nothing. Without a key, a second request while a release runs is answered with 409.

## Audit events

`ui|api.release.created`, `system.release.started`, `release.checks_overridden`, `system.release.blocked`, `system.release.paused`, `ui|api.release.resumed`, `ui|api.release.cancelled`, `system.release.finished`, and `ui.release_group.*` for changes to a group. Every release carries a `correlation_id` that appears in its audit events.

## Source map

| Area | Files |
| --- | --- |
| Data | `database/migrations/2026_10_12_200000_create_release_groups_tables.php`, `app/Models/ReleaseGroup*.php`, `app/Models/Release*.php`, `app/Enums/Release*.php` |
| Rules | `app/Services/Release/ReleaseGraph.php`, `ReleasePreflight.php`, `app/Policies/ReleaseGroupPolicy.php` |
| Engine | `app/Services/Release/ReleaseOrchestrator.php`, `app/Jobs/ProcessReleaseJob.php`, `app/Jobs/ReconcileReleasesJob.php`, wake-up hook in `app/Models/ApplicationDeploymentQueue.php` |
| Actions | `app/Actions/Release/CreateRelease.php`, `CancelRelease.php`, `ResumeRelease.php` |
| UI | `app/Livewire/Project/Release/*`, `resources/views/livewire/project/release/*` |
| API | `app/Http/Controllers/Api/ReleasesController.php` |

## Limits

- Members are applications. Services and databases are not orchestrated by release groups yet.
- Verification uses Coolify's container status and the deployment's own health check; it does not call application endpoints.
- At most 50 resources per group and 10 parallel deployments per release.
