# Deployment lifecycle

A deployment keeps its existing `status` (`queued`, `in_progress`, `finished`, `failed`, `cancelled-by-user`). The status is also the admission control: a row with `in_progress` occupies one of the server's concurrent build slots (Server → Advanced → Builds), and `next_queuable()` counts those rows. That meaning is unchanged.

Next to the status every deployment now records a **phase**, so the UI and the API can tell a reserved slot from a deployment that really runs.

| Phase | Status | Meaning |
| --- | --- | --- |
| `queued` | `queued` | Waiting for a free slot on the server. |
| `admitted` | `in_progress` | A slot is reserved. The job is being handed to the queue. |
| `dispatched` | `in_progress` | The job is in the queue and waits for a worker. |
| `preparing` | `in_progress` | A worker started: helper container, source checkout. |
| `building` | `in_progress` | Building the image. |
| `pushing` | `in_progress` | Pushing the image to the registry. |
| `deploying` | `in_progress` | Starting the new containers. |
| `verifying` | `in_progress` | Waiting for the health check. |
| `finished`, `failed`, `cancelled` | matching status | Terminal. A terminal phase never changes again. |

Stored with it: `admitted_at`, `dispatched_at`, `started_at`, `phase_changed_at` and a short `phase_history` (at most 30 entries). The deployment page shows queue wait and run time from these timestamps; the deployments indicator and the dashboard show *Waiting for worker* instead of a spinner while a slot is reserved but no worker runs.

Rows from before this change have no phase and are shown as *In progress*, *Queued* and so on, exactly as before.

## Stalled deployments

A slot can stay reserved forever when a job is lost between admission and the worker (for example a Redis flush or a restart at the wrong moment). `RecoverStalledDeploymentsJob` runs every five minutes and releases such a slot when all of the following hold:

- status `in_progress`, `admitted_at` set, `started_at` empty (no worker ever started it);
- admitted longer ago than the larger of 15 minutes and the server's deployment timeout;
- Horizon does not report the job as reserved.

The deployment becomes `failed` with a log entry, an audit event `system.deployment.stalled_released` is written and the next queued deployment of the server starts. Deployments that a worker started are never touched by this job; they end through their own timeout.

A worker that picks up a deployment whose slot was already released stops without doing anything.
