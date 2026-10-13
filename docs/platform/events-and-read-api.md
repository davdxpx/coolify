# Events, webhooks and the read API

## Events

Coolify records an event for:

| Type | When |
| --- | --- |
| `release.started`, `release.paused`, `release.completed`, `release.partially_failed`, `release.failed`, `release.cancelled` | A release changes state. `data` has the group, state, commit and the reason of a pause or failure. |
| `deployment.finished`, `deployment.failed`, `deployment.cancelled` | A deployment ends. `data` has the application, server, commit, pull request id and image digest. |
| `deployment_freeze.created`, `deployment_freeze.ended` | A freeze is created or ended. |

Events are stored in the same database transaction as their deliveries (outbox) and kept for 30 days. They contain identifiers and states, never variable values or secrets. Each release event carries the release's `correlation_id`.

### Feed

`GET /api/v1/events?after=<id>&types=<comma separated>&limit=<1-500>` returns events in recording order with `next_after`. A consumer stores `next_after` and asks again later; nothing is lost while it is offline for less than 30 days.

### Webhooks

Event webhooks push the same events to HTTPS endpoints (**Event webhooks** page, linked from the Releases page, or `/api/v1/event-subscriptions`).

- Only public HTTPS URLs are accepted. The address is checked again before each delivery and the connection is pinned to the checked IP addresses; private, loopback and link-local targets and redirects are refused.
- Each request carries `Coolify-Event`, `Coolify-Delivery` and `Coolify-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of "<time>.<body>">`. The secret (`whsec_...`) is shown once when the endpoint is created. Verify like this:

  ```php
  [$t, $v1] = sscanf($header, 't=%d,v1=%s');
  $valid = abs(time() - $t) <= 300 && hash_equals(hash_hmac('sha256', "{$t}.{$body}", $secret), $v1);
  ```

- A delivery is retried after 30 s, 2 min, 10 min, 30 min, 1 h, 2 h and 4 h (8 attempts in total). Deliveries survive restarts: a scheduled job hands due deliveries to workers every minute.
- After 50 failed deliveries in a row an endpoint is disabled with the reason; **Send test** enables it again.
- Delivery order is not guaranteed; use the event `id` to order.

## Read API

| Endpoint | Content |
| --- | --- |
| `GET /api/v1/platform/overview` | Coolify version and distribution build, servers with build capacity, deployments in progress, queued and of the last 24 h by status, active and recent releases, freezes, database backup freshness, event webhook health. |
| `GET /api/v1/platform/metrics?hours=24` | Deployments by status, queue wait and execution time (count, p50, p95, max) overall and per server, release outcomes and durations, failed event deliveries. |
| `GET /api/v1/applications/{uuid}/rollback-candidates?check_images=false` | Earlier successful deployments (one per commit) with their images, configuration changes and shared variable changes since then, warnings. `check_images=true` asks the server which images still exist. A rollback starts the old image with the current configuration; database changes are not reverted. |
| `GET /api/v1/backups/freshness?environment_uuid=` | Per database: enabled schedule, last successful backup, age, last attempt and the last recorded restore test. |
| `GET, POST /api/v1/databases/{uuid}/restore-evidence` | Restore tests recorded by people or automation (`passed`/`failed`, `manual`/`automated`, optional backup execution and notes). Coolify stores the evidence; it does not restore. |

All endpoints are team-scoped through the API token and documented in the OpenAPI specification (tags *Releases*, *Configuration*, *Events*, *Platform*).

## MCP

The Coolify MCP server offers read-only tools for the same information: `get_platform_overview` (optionally with metrics), `list_releases`, `get_release` and `get_configuration_report`. `coolify_help` lists them under the intent `releases`.
