# Moving an official installation to a custom distribution

This is a one-time, manual migration. Coolify never switches distributions on its own. The procedure keeps the existing database, volumes, `.env`, SSH keys, proxy and every managed resource. Only the `coolify` container is replaced; `coolify-db` and `coolify-redis` stay running unless their image changes.

Host-specific values for a production instance belong in that instance's runbook. This document is the generic procedure.

## What changes and what does not

| Changes | Stays |
| --- | --- |
| The image of the `coolify` service (pinned by digest in `docker-compose.custom.yml`) | `coolify-db` and its volume `coolify-db` |
| `docker-compose.yml`, `docker-compose.prod.yml`, `.env.production`, `upgrade-postgres.sh` from the distribution image (same as the matching official release) | `coolify-redis` and its volume |
| `COOLIFY_VERSION` in `.env` (set to the base version) | `.env` secrets: `APP_KEY`, database and Redis passwords, Pusher keys |
| | `/data/coolify/ssh`, `applications`, `databases`, `services`, `backups` |
| | `coolify-proxy`, `coolify-sentinel`, every application, service and database container |
| | Domains, certificates, proxy configuration, deployment history, users, teams |

Managed applications are not redeployed: Coolify only redeploys on a deployment request, and the start-up of the new container marks interrupted deployments as failed (official behaviour), so do not migrate while deployments run.

## Prerequisites

1. **Same base version.** The distribution build must be based on the official version that runs now (or a newer one). A distribution built on an older base is refused by the checks and must not be forced: its code would run against a newer schema.
2. **Tested.** The exact digest passed Distribution Build and ran on a separate test installation for at least one day.
3. **Registry access.** `docker pull ghcr.io/<owner>/<repo>@sha256:<digest>` works on the host (make the package public, or `docker login ghcr.io` with a read-only token).
4. **Backup restore rehearsed.** A fresh `coolify-db` backup exists and was restored once on a separate machine ([recovery.md](recovery.md)).
5. **Inventory taken.** `sudo bash inventory.sh > inventory-before.md` (from `scripts/distribution/inventory.sh` of the distribution) and stored outside the host. It prints names, digests, counts and checksums only.

## Abort criteria

Stop and do not continue when any of these holds:

- The inventory shows deployments queued or in progress.
- `docker-compose.custom.yml` already pins `services.coolify.image` to something you cannot explain.
- The running official version is newer than the distribution's base version.
- `docker compose ... config` fails before you change anything.
- The digest cannot be pulled, or `build.json` in the image does not show the expected commit.
- Disk space for Docker is below 3 GB.

## Procedure

All commands run on the Coolify host as root. `TARGET` is the full image reference with digest.

```bash
TARGET=ghcr.io/<owner>/<repo>@sha256:<digest>
cd /data/coolify/source

# 1. Read-only checks
docker pull "$TARGET"
docker run --rm --entrypoint cat "$TARGET" /var/www/html/build.json
docker inspect --format '{{.Config.Image}} {{.Image}}' coolify
FILES="-f docker-compose.yml -f docker-compose.prod.yml"
[ -f docker-compose.custom.yml ] && FILES="$FILES -f docker-compose.custom.yml"
docker compose --env-file .env $FILES config --images

# 2. Backups of the configuration (the database backup was taken in the prerequisites)
STAMP=$(date +%Y-%m-%d-%H-%M-%S)
mkdir -p backups/distribution-migration-$STAMP
cp -p docker-compose.yml docker-compose.prod.yml .env backups/distribution-migration-$STAMP/
[ -f docker-compose.custom.yml ] && cp -p docker-compose.custom.yml backups/distribution-migration-$STAMP/
docker inspect --format '{{.Image}}' coolify > backups/distribution-migration-$STAMP/previous-image-id.txt

# 3. Hand over to the upgrade script bundled in the distribution image.
#    It verifies the image, installs the bundled Compose files, pins the digest
#    in docker-compose.custom.yml (keeping all other keys and comments),
#    validates the effective configuration and restarts only what changed.
docker create --name coolify-distribution-bundle "$TARGET"
docker cp coolify-distribution-bundle:/usr/local/share/coolify/source/upgrade-distribution.sh ./upgrade-distribution.sh
docker rm coolify-distribution-bundle
bash ./upgrade-distribution.sh \
  --upgrade-id "$(cat /proc/sys/kernel/random/uuid)" \
  --image ghcr.io/<owner>/<repo> --digest sha256:<digest> \
  --commit <full commit> --build <build> --base-version <base> --version <label> \
  --distribution <owner>/<repo> --helper-version <helper version> --registry <docker.io|ghcr.io> \
  --expected-migration <newest migration of the release> \
  --signature-issuer https://token.actions.githubusercontent.com \
  --signature-identity-regexp '<identity regexp from build.json>'

# 4. Follow the switch
tail -f upgrade-distribution-*.log          # Ctrl+C when "Upgrade complete" or an error appears
cat .upgrade-distribution-result
```

The script refuses to continue on any failed verification and restores the previous configuration. If the new container does not become healthy and no migration ran, it starts the previous image again by itself.

## Verification after the switch

| Check | How |
| --- | --- |
| The distribution image runs | `docker inspect --format '{{.Config.Image}}' coolify` shows the digest; `docker image inspect --format '{{json .RepoDigests}}' $(docker inspect --format '{{.Image}}' coolify)` contains it |
| The expected build runs | `docker exec coolify cat /var/www/html/build.json` |
| Coolify shows the right version | Settings → Updates → Running version: digest "Matches release" |
| Dashboard, login, API | Log in; `curl -fsS https://<console>/api/health`; an API token with `read` lists servers |
| MCP | Existing MCP client lists the infrastructure overview |
| Data | Projects, resources, servers (reachable), shared variables present; compare the counts in a second inventory |
| Background work | Horizon and scheduler running (`docker logs coolify` shows them); a scheduled backup runs |
| Deployment queue | Deploy a non-critical application; it moves through queued, in progress, finished |
| Applications and proxy | Production URLs answer with valid certificates; `coolify-proxy` was not restarted |
| Official updates cannot overwrite | Settings → Updates shows the distribution channel; the official "Update available" badge no longer appears for official releases |
| Distribution channel works | Check now shows the channel release |
| Upstream information | Settings → Updates → Official Coolify shows the latest official version |

## Returning to the official distribution

Also a manual migration: restore the backed-up `docker-compose.custom.yml` (or remove the `image` key), set `COOLIFY_VERSION` and `LATEST_IMAGE` to an official version that is **not older** than the distribution's base version, and run the official upgrade script. Database migrations of the distribution must be compatible with that official version; when the distribution added its own migrations, restore the database backup from before the migration instead.
