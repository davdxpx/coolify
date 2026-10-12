# Deployment freezes, artifacts and build capacity

## Deployment freezes

A freeze blocks deployments of the whole team, one project or one environment for a period. Manage freezes under **Deployment freezes** (linked from every Releases page) or through the API.

The check runs inside `queue_application_deployment()`, the function every deployment path uses: the UI, the API, Git webhooks, MCP, scheduled and release deployments. A blocked deployment is not queued; the caller receives the usual *skipped* result with the freeze reason (for example the deploy API answers `"Deployments are frozen until ...: Year-end freeze"` for that resource, a webhook delivery lists the application as skipped).

| Setting | Effect |
| --- | --- |
| Starts, ends | A freeze without an end lasts until someone ends it. Planned freezes are shown on the Releases page before they start. |
| Allow rollbacks | Rollbacks to an earlier deployment still run (default on). |
| Allow override | A release can continue during the freeze with a reason. The override is written to the audit log (`deployment.freeze_overridden`, `release.checks_overridden`). Manual deployments are not overridable. |

Not affected: preview deployments of pull requests and the per-server parts of a multi-server deployment that was admitted before the freeze.

Audit events: `ui|api.deployment_freeze.created`, `ui|api.deployment_freeze.ended`, `system.deployment.freeze_blocked`, `deployment.freeze_overridden`.

## Checks before a release

Besides the built-in checks of a release (resources, dependencies, servers), a release group can require:

| Check | Configured in the group | Overridable |
| --- | --- | --- |
| `deployment_freeze` | Always on | If every active freeze allows it |
| `release_window` | Days, from, until, time zone | Yes |
| `backup_freshness` | Required database backup age in hours: every database of the environment (standalone and service databases) needs an enabled schedule with a successful run that is not older | Yes |

A failed check pauses the release before its first deployment. On the release page, admins can tick the failed checks, give a reason and continue. Through the API, pass `acknowledged_checks` and `acknowledgement_reason` when starting a release (useful for a pipeline that ships an approved hotfix), or `POST /releases/{uuid}/resume` with `mode: override`. The release stores which checks were overridden and why.

## Deployed images (artifacts)

When a deployment finishes, Coolify records the images that run for it before the deployment is marked finished: per container the compose service, the image reference, the local image id and, if the image came from or was pushed to a registry, its repository digest. They are stored on the deployment (`artifacts`), shown on the deployment page, returned by the deployments API and copied to release members (`image_digest`). Swarm servers and preview deployments are skipped. A failure to read the images is logged in the deployment log and never fails the deployment.

There is no separate artifact promotion between environments: applications that build on the server produce a new local image per environment. For identical artifacts across environments, push to a registry from one environment and deploy the same digest in the other (Docker Image build pack with the digest as tag).

## Build capacity

`BuildCapacity` reports, per server, the configured concurrent builds, slots that run a deployment, slots reserved for a deployment that waits for a worker, free slots, queued deployments against the queue limit and the age of the oldest queued deployment. It is shown on the Releases page for the servers of the environment, used as a release warning when a queue is almost full, and available at `GET /api/v1/servers/{uuid}/build-capacity`.
