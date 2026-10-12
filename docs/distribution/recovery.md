# Rollback and disaster recovery

A Coolify update replaces the control plane container and may run database migrations at start. Laravel migrations are not reversed automatically, and an older image cannot be trusted against a newer schema. A one-click rollback is therefore only offered when it is provably safe.

## The four recovery levels

| Level | When | How | Data loss |
| --- | --- | --- | --- |
| **1. Automatic revert** | The new container does not become healthy and no migration ran | The host script restores the previous Compose files and `.env` and starts the previous image. Result: *Failed, previous image restored* | None |
| **2. Image rollback** | The update succeeded, no migration ran since | Settings → Updates → Previous version → Restore previous version. Runs the normal update path in rollback mode with schema check | None |
| **3. Image plus database restore** | Migrations ran, the new version is broken | Restore the Coolify database backup taken before the update and start the previous image (procedure below) | Changes made in Coolify after the backup |
| **4. Forward fix** | Migrations ran and a restore would lose too much | Fix the problem on `main`, let Distribution Build publish a new build, promote it, install it | None |

Coolify decides between level 2 and level 3 from the update history: each update records the newest applied migration before it started. If the database now contains a newer migration, Coolify does not offer the image rollback and explains why. The host script makes the same decision for level 1 by reading `max(migration)` before and after the start. When it cannot read it, it does not revert automatically and reports *Failed, recovery required*.

Releases declare their newest migration in the manifest. An update to a release that does not know the migrations already applied in the database is refused, and so is a rollback without a declared migration state.

## What is backed up before each update

- `/data/coolify/source/backups/distribution-upgrade-<date>/`: `docker-compose.yml`, `docker-compose.prod.yml`, `docker-compose.custom.yml`, `.env`, `.env.production`, `upgrade-postgres.sh`, and `previous-image.txt` (image ID, reference and newest migration).
- The previous image stays on the host as `coolify-distribution:rollback` and is excluded from Docker cleanup.
- The database is **not** backed up by the script. The update checks require a successful backup of the Coolify database that is not older than the configured maximum (Settings → Updates → Automatic updates → Maximum backup age, default 24 hours) whenever the release may run migrations. An admin can only proceed without it by acknowledging the check with a reason; the acknowledgement is stored in the update history and the audit log.

A successful backup run does not prove that the backup restores. Rehearse the restore on a separate machine (see below) before you rely on it.

## Level 3: restoring the previous image and the database

Run on the Coolify host as root. Replace `<date>` with the backup folder of the failed update and `<dump>` with the backup taken before it.

```bash
cd /data/coolify/source
BACKUP=backups/distribution-upgrade-<date>
cat "$BACKUP/previous-image.txt"

# 1. Stop only the control plane. Applications, databases and the proxy keep running.
docker stop coolify

# 2. Restore the Coolify database from the backup taken before the update.
DB_USER=$(grep '^DB_USERNAME=' .env | cut -d= -f2-)
docker exec -i coolify-db psql -U "$DB_USER" -d postgres -c 'DROP DATABASE coolify WITH (FORCE);'
docker exec -i coolify-db psql -U "$DB_USER" -d postgres -c 'CREATE DATABASE coolify;'
docker exec -i coolify-db pg_restore -U "$DB_USER" -d coolify --no-owner < <dump>

# 3. Restore the configuration that selected the previous image.
cp -p "$BACKUP/docker-compose.yml" "$BACKUP/docker-compose.prod.yml" "$BACKUP/.env" .
cp -p "$BACKUP/docker-compose.custom.yml" . 2>/dev/null || rm -f docker-compose.custom.yml

# 4. Start the previous version.
docker rm coolify
docker compose --env-file .env -f docker-compose.yml -f docker-compose.prod.yml \
  $( [ -f docker-compose.custom.yml ] && echo -f docker-compose.custom.yml ) up -d --wait coolify
```

Coolify backups of `coolify-db` are custom-format dumps (`pg_dump -Fc`); use `pg_restore` as shown. If your dump is plain SQL, use `psql -f` instead. Keep `APP_KEY` from the restored `.env`: without the same key, encrypted values in the database (environment variables, private keys, tokens) cannot be decrypted.

After the restore, Settings → Updates still shows the failed update; record the restore in your incident notes. Deployments that ran between the backup and the restore are missing from the history.

## Disaster recovery of the whole host

Keep these items outside the host:

| Item | Where |
| --- | --- |
| Coolify database dumps | Settings → Backup with an S3 destination |
| `/data/coolify/source/.env` (contains `APP_KEY`, database and Redis passwords) | Password manager or secret store; never in Git |
| `/data/coolify/ssh/keys` | Secret store |
| `/data/coolify/source/docker-compose.custom.yml` | With the infrastructure notes |
| The installed distribution digest | Settings → Updates or the manifest |
| Registry access for the host | `docker login ghcr.io` with a read-only token if the package is private |

To rebuild a host: install Docker, restore `/data/coolify/source/.env` and the SSH keys, create the `coolify` network, place the Compose files from the distribution image bundle (`docker create` the image, `docker cp` `/usr/local/share/coolify/source/.`), pin the digest in `docker-compose.custom.yml`, start `coolify-db` and `coolify-redis`, restore the database, then start `coolify`. Persistent volumes of applications are not part of the Coolify backup; they need their own backups.

## Restore rehearsal

On a disposable VM:

1. Copy the newest `coolify-db` dump and the `.env` of production (secure transfer, delete afterwards).
2. Install the same distribution digest with `scripts/distribution/upgrade-smoke-test.sh` (fresh installation mode), stop `coolify`, restore the dump as in level 3, start `coolify`.
3. Check the login, the project list and that an environment variable value can be revealed (proves `APP_KEY` matches).
4. Do not let the rehearsal instance reach production servers: remove the private keys from the restored database or block outbound SSH on the VM before step 2.
5. Delete the VM and the copied secrets.
