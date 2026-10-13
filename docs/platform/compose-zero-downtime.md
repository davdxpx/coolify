# Zero-downtime deployments for Docker Compose applications

Coolify deploys Docker Compose applications by stopping the running containers and then starting the new ones, which interrupts traffic for the time the new version needs to start. With **Zero-downtime deployment** (Application → Advanced → Docker compose), the new version starts next to the running one instead.

## How it works

1. The build and image pull run as before.
2. The new version starts as a second Compose project: `<uuid>-green` when `<uuid>` runs, and `<uuid>` when `<uuid>-green` runs. Container names are unique per deployment, volumes keep their fixed names, and both projects use the application network.
3. `docker compose up -d --wait` waits up to five minutes until every service runs and every health check passes.
4. Traefik routes the same domain to the old and the new containers while both run, because their router and service labels are identical, and sends no traffic to containers whose health check has not passed yet (both verified). Caddy should behave the same because caddy-docker-proxy merges identical site labels into several upstreams; this was not measured.
5. When the new version is healthy, the old containers are stopped with the application's stop grace period.
6. If the new version does not start or does not become healthy in time, its containers are removed, the old version keeps serving and the deployment fails with that reason.

Measured locally with Traefik 3.6 and a request every 50 ms: across a switch, 577 of 578 requests succeeded and were answered by the old or the new version; the one failure occurred when the old container was killed after its stop grace period because it ignored SIGTERM, the same window Coolify's rolling update for single-container applications has. During a failed deployment, all 463 requests were answered by the running version. Applications that finish in-flight requests on SIGTERM avoid the remaining window.

## When it is not possible

Coolify checks the Compose file and refuses to enable the mode, or falls back to the normal deployment with a note in the deployment log, when:

- a service publishes host ports (`ports:`), sets `container_name` or uses `network_mode`;
- a service runs a database or another stateful image (PostgreSQL, MySQL, MariaDB, MongoDB, Redis, ClickHouse, Elasticsearch, RabbitMQ, MinIO and similar): it would run twice on the same data. Move such services into separate resources;
- the application uses raw compose deployment, a custom start command, consistent container names or a Swarm server.

Warnings that do not block:

- Services with volumes share them with the other version while both run.
- Without a health check in the Compose file, Coolify waits for a health check of the image or only until the container runs.
- With several services, service names on the application network resolve to containers of both versions during the switch.

Pull request preview deployments always use the normal deployment.

## Settings and API

- UI: Application → Advanced → Docker compose → Zero-downtime deployment, with the result of the check.
- API: `GET /api/v1/applications/{uuid}/zero-downtime` returns `enabled`, `eligible`, `blocking` and `warnings`; `PATCH` with `{"enabled": true}` enables it and answers 422 with the reasons when the Compose file does not allow it.

## Limits

- Deleting an application with named volumes: volumes that were first created by the `<uuid>-green` project are not removed by Coolify's volume cleanup, which uses the `<uuid>` project. Remove them with `docker volume rm` if needed.
- The wait timeout is five minutes.
