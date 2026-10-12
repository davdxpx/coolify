#!/bin/bash
## Upgrades a Coolify custom distribution to an exact image digest.
## Shipped inside the distribution image and started by Coolify; it is never downloaded from a CDN.

set -u
umask 077
export LC_ALL=C

SCRIPT_PATH="$(readlink -f "$0")"
LOCK_HELD="false"

SOURCE_DIR="${COOLIFY_SOURCE_DIR:-/data/coolify/source}"
ENV_FILE="$SOURCE_DIR/.env"
STATUS_FILE="$SOURCE_DIR/.upgrade-status"
RESULT_FILE="$SOURCE_DIR/.upgrade-distribution-result"
LOCK_DIR="$SOURCE_DIR/.upgrade-distribution.lock"
APP_CONTAINER="${COOLIFY_APP_CONTAINER:-coolify}"
DB_CONTAINER="${COOLIFY_DB_CONTAINER:-coolify-db}"
BUNDLE_DIR="/usr/local/share/coolify/source"
YQ_IMAGE="ghcr.io/mikefarah/yq:4.47.2@sha256:76def1f56f456ecc1c3173ea275218ee17139bc2018c5a07887b15afd88ec03e"
COSIGN_IMAGE="ghcr.io/sigstore/cosign/cosign:v2.5.3@sha256:f1946d0f30fc8e3777b02f2201e02efdba9fe38f4918162f937052fac98e083f"
WAIT_TIMEOUT="${COOLIFY_UPGRADE_WAIT_TIMEOUT:-300}"

PHASE="prepare"
CONTEXT_FILE=""
UPGRADE_ID=""
IMAGE=""
DIGEST=""
COMMIT=""
BUILD=""
BASE_VERSION=""
VERSION=""
DISTRIBUTION=""
HELPER_VERSION=""
REGISTRY_URL="docker.io"
EXPECTED_MIGRATION=""
SIGNATURE_ISSUER=""
SIGNATURE_IDENTITY_REGEXP=""
ROLLBACK="false"

while [ $# -gt 0 ]; do
    case "$1" in
    --phase) PHASE="${2:-}"; shift 2 ;;
    --context) CONTEXT_FILE="${2:-}"; shift 2 ;;
    --upgrade-id) UPGRADE_ID="${2:-}"; shift 2 ;;
    --image) IMAGE="${2:-}"; shift 2 ;;
    --digest) DIGEST="${2:-}"; shift 2 ;;
    --commit) COMMIT="${2:-}"; shift 2 ;;
    --build) BUILD="${2:-}"; shift 2 ;;
    --base-version) BASE_VERSION="${2:-}"; shift 2 ;;
    --version) VERSION="${2:-}"; shift 2 ;;
    --distribution) DISTRIBUTION="${2:-}"; shift 2 ;;
    --helper-version) HELPER_VERSION="${2:-}"; shift 2 ;;
    --registry) REGISTRY_URL="${2:-}"; shift 2 ;;
    --expected-migration) EXPECTED_MIGRATION="${2:-}"; shift 2 ;;
    --signature-issuer) SIGNATURE_ISSUER="${2:-}"; shift 2 ;;
    --signature-identity-regexp) SIGNATURE_IDENTITY_REGEXP="${2:-}"; shift 2 ;;
    --rollback) ROLLBACK="true"; shift ;;
    *) echo "Unknown argument: $1" >&2; exit 2 ;;
    esac
done

if [ "$PHASE" = "switch" ]; then
    if [ -z "$CONTEXT_FILE" ] || [ ! -f "$CONTEXT_FILE" ]; then
        echo "Missing switch context." >&2
        exit 2
    fi
    while IFS='=' read -r key value; do
        case "$key" in
        UPGRADE_ID | IMAGE | DIGEST | COMMIT | BUILD | BASE_VERSION | VERSION | DISTRIBUTION | HELPER_VERSION | REGISTRY_URL | EXPECTED_MIGRATION | ROLLBACK | LOGFILE | BACKUP_DIR | STAGE_DIR | MIGRATION_BEFORE | RECREATE_DATA_SERVICES | DOCKER_CONFIG_MOUNT)
            printf -v "$key" '%s' "$value"
            ;;
        esac
    done <"$CONTEXT_FILE"
    LOCK_HELD="true"
fi

TARGET="${IMAGE}@${DIGEST}"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >>"$LOGFILE"
}

write_status() {
    echo "$1|$2|$(date -Iseconds)" >"$STATUS_FILE"
}

write_result() {
    local message
    message=$(printf '%s' "$2" | tr '\n|' '  ')
    echo "${UPGRADE_ID}|$1|${message}|$(date -Iseconds)" >"$RESULT_FILE"
}

release_lock() {
    if [ "$LOCK_HELD" = "true" ]; then
        rmdir "$LOCK_DIR" 2>/dev/null || true
        LOCK_HELD="false"
    fi
}

compose_args() {
    local args="-f $SOURCE_DIR/docker-compose.yml -f $SOURCE_DIR/docker-compose.prod.yml"
    if [ -f "$SOURCE_DIR/docker-compose.custom.yml" ]; then
        args="$args -f $SOURCE_DIR/docker-compose.custom.yml"
    fi
    if [ -f "$SOURCE_DIR/docker-compose.postgres-upgrade.yml" ]; then
        args="$args -f $SOURCE_DIR/docker-compose.postgres-upgrade.yml"
    fi
    printf '%s' "$args"
}

abort() {
    log "ERROR: $1"
    write_status "error" "$1"
    write_result "aborted" "$1 The previous version keeps running."
    echo "ERROR: $1" >&2
    release_lock
    exit 1
}

valid() {
    printf '%s' "$2" | grep -Eq "$1"
}

compose_files() {
    COMPOSE_FILES=(-f "$SOURCE_DIR/docker-compose.yml" -f "$SOURCE_DIR/docker-compose.prod.yml")
    if [ -f "$SOURCE_DIR/docker-compose.custom.yml" ]; then
        COMPOSE_FILES+=(-f "$SOURCE_DIR/docker-compose.custom.yml")
    fi
    if [ -f "$SOURCE_DIR/docker-compose.postgres-upgrade.yml" ]; then
        COMPOSE_FILES+=(-f "$SOURCE_DIR/docker-compose.postgres-upgrade.yml")
    fi
}

compose_value() {
    compose_files
    docker compose --env-file "$ENV_FILE" "${COMPOSE_FILES[@]}" config 2>>"$LOGFILE" | docker run --rm -i "$YQ_IMAGE" "$1" 2>>"$LOGFILE"
}

latest_migration() {
    local user database
    user=$(grep -E '^DB_USERNAME=' "$ENV_FILE" | tail -n1 | cut -d '=' -f2-)
    database=$(grep -E '^DB_DATABASE=' "$ENV_FILE" | tail -n1 | cut -d '=' -f2-)
    docker exec "$DB_CONTAINER" psql -U "${user:-coolify}" -d "${database:-coolify}" -tAc 'select max(migration) from migrations' 2>/dev/null | tr -d '[:space:]'
}

pull_or_reuse() {
    if docker pull "$1" >>"$LOGFILE" 2>&1; then
        return 0
    fi
    if docker image inspect "$1" >/dev/null 2>&1; then
        log "WARNING: $1 could not be pulled; the local copy is used"
        return 0
    fi
    return 1
}

set_env_var() {
    if grep -q "^$1=" "$ENV_FILE"; then
        sed -i "s|^$1=.*|$1=$2|" "$ENV_FILE"
    else
        printf '%s=%s\n' "$1" "$2" >>"$ENV_FILE"
    fi
}

update_env_var() {
    if grep -q "^$1=$" "$ENV_FILE"; then
        sed -i "s|^$1=$|$1=$2|" "$ENV_FILE"
    elif ! grep -q "^$1=" "$ENV_FILE"; then
        printf '%s=%s\n' "$1" "$2" >>"$ENV_FILE"
    fi
}

restore_configuration() {
    for file in docker-compose.yml docker-compose.prod.yml docker-compose.custom.yml .env .env.production upgrade-postgres.sh; do
        if [ -f "$BACKUP_DIR/$file" ]; then
            cp -p "$BACKUP_DIR/$file" "$SOURCE_DIR/$file"
        elif [ -f "$BACKUP_DIR/.absent-$file" ]; then
            rm -f "$SOURCE_DIR/$file"
        fi
    done
}

run_compose_up() {
    docker run -v "$SOURCE_DIR:$SOURCE_DIR" -v /var/run/docker.sock:/var/run/docker.sock ${DOCKER_CONFIG_MOUNT} --rm \
        "${REGISTRY_URL:-docker.io}/coollabsio/coolify-helper:${HELPER_VERSION}" \
        bash -c "docker compose --env-file $ENV_FILE $(compose_args) up -d --remove-orphans --wait --wait-timeout $WAIT_TIMEOUT" >>"$LOGFILE" 2>&1
}

remove_containers() {
    for container in "$@"; do
        if docker ps -a --format '{{.Names}}' | grep -qx "$container"; then
            log "Stopping and removing container $container"
            docker stop "$container" >>"$LOGFILE" 2>&1 || true
            docker rm "$container" >>"$LOGFILE" 2>&1 || true
        fi
    done
}

switch_phase() {
    log "Switch phase started for $TARGET"
    write_status "4" "Restarting Coolify"

    if [ "$RECREATE_DATA_SERVICES" = "true" ]; then
        remove_containers "$APP_CONTAINER" "$DB_CONTAINER" coolify-redis coolify-realtime
    else
        remove_containers "$APP_CONTAINER" coolify-realtime
    fi

    write_status "5" "Starting Coolify $VERSION"
    if run_compose_up; then
        log "Coolify $VERSION is running and healthy"
        write_status "6" "Upgrade complete"
        write_result "completed" "Coolify $VERSION started. Coolify verifies the running build and image digest."
        rm -rf "$STAGE_DIR"
        release_lock
        sleep 10
        rm -f "$STATUS_FILE"
        return 0
    fi

    log "ERROR: Coolify $VERSION did not become healthy"
    local migration_after
    migration_after=$(latest_migration)
    log "Latest migration before: ${MIGRATION_BEFORE:-unknown}, after: ${migration_after:-unknown}"

    if [ -n "$MIGRATION_BEFORE" ] && [ "$migration_after" = "$MIGRATION_BEFORE" ]; then
        log "No migration ran; restoring the previous configuration and image"
        restore_configuration
        remove_containers "$APP_CONTAINER"
        if run_compose_up; then
            write_status "error" "The new version did not become healthy. The previous version was restored."
            write_result "reverted" "Coolify $VERSION did not become healthy. No database migration had run, so the previous image was restored. Log: $LOGFILE"
        else
            write_status "error" "The new version and the restore of the previous version both failed."
            write_result "needs_recovery" "Coolify $VERSION did not become healthy and the previous image could not be started again. Configuration backup: $BACKUP_DIR. Log: $LOGFILE"
        fi
    else
        write_status "error" "The new version did not become healthy after database migrations ran."
        write_result "needs_recovery" "Coolify $VERSION did not become healthy and database migrations ran (${MIGRATION_BEFORE:-unknown} → ${migration_after:-unknown}). It was not reverted automatically. Restore the database backup together with the previous image, or install a fixed build. Configuration backup: $BACKUP_DIR. Log: $LOGFILE"
    fi
    release_lock
    return 1
}

if [ "$PHASE" = "switch" ]; then
    switch_phase
    exit $?
fi

if [ "$PHASE" != "prepare" ]; then
    echo "Unknown phase: $PHASE" >&2
    exit 2
fi

DATE=$(date +%Y-%m-%d-%H-%M-%S)
LOGFILE="$SOURCE_DIR/upgrade-distribution-${DATE}.log"
BACKUP_DIR="$SOURCE_DIR/backups/distribution-upgrade-${DATE}"
STAGE_DIR="$SOURCE_DIR/.distribution-stage-${DATE}"
touch "$LOGFILE"

valid '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' "$UPGRADE_ID" || { echo "Invalid upgrade id." >&2; exit 2; }
valid '^[a-z0-9]+([.-][a-z0-9]+)*(:[0-9]{1,5})?(/[a-z0-9]+(([._]|__|-+)[a-z0-9]+)*)+$' "$IMAGE" || abort "Invalid image repository."
valid '^sha256:[0-9a-f]{64}$' "$DIGEST" || abort "Invalid image digest."
valid '^[0-9a-f]{40}$' "$COMMIT" || abort "Invalid commit."
valid '^[1-9][0-9]{0,9}$' "$BUILD" || abort "Invalid build number."
valid '^[0-9]+\.[0-9]+(\.[0-9]+)?(-(alpha|beta|rc)\.[0-9]+(\.[0-9]+)*)?$' "$BASE_VERSION" || abort "Invalid base version."
valid '^[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}$' "$VERSION" || abort "Invalid version label."
valid '^[A-Za-z0-9][A-Za-z0-9._-]*/[A-Za-z0-9._-]+$' "$DISTRIBUTION" || abort "Invalid distribution name."
valid '^[0-9A-Za-z._-]{1,64}$' "$HELPER_VERSION" || abort "Invalid helper version."
valid '^(docker\.io|ghcr\.io)$' "$REGISTRY_URL" || abort "Invalid registry."
if [ -n "$EXPECTED_MIGRATION" ]; then
    valid '^[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]{1,200}$' "$EXPECTED_MIGRATION" || abort "Invalid migration marker."
fi

if ! mkdir "$LOCK_DIR" 2>/dev/null; then
    if [ -n "$(find "$LOCK_DIR" -maxdepth 0 -mmin +60 2>/dev/null)" ]; then
        rmdir "$LOCK_DIR" 2>/dev/null
        mkdir "$LOCK_DIR" 2>/dev/null || { echo "Another distribution upgrade is running." >&2; exit 1; }
    else
        log "ERROR: Another distribution upgrade is running"
        echo "Another distribution upgrade is running." >&2
        exit 1
    fi
fi
LOCK_HELD="true"

{
    echo "============================================================"
    echo "Coolify distribution upgrade"
    echo "Started: $(date '+%Y-%m-%d %H:%M:%S')"
    echo "Upgrade: $UPGRADE_ID"
    echo "Target: $VERSION (build $BUILD, base $BASE_VERSION)"
    echo "Image: $TARGET"
    echo "Commit: $COMMIT"
    echo "Rollback: $ROLLBACK"
    echo "============================================================"
} >>"$LOGFILE"

rm -f "$RESULT_FILE"

if [ ! -f "$ENV_FILE" ] || [ ! -f "$SOURCE_DIR/docker-compose.yml" ] || [ ! -f "$SOURCE_DIR/docker-compose.prod.yml" ]; then
    abort "The Coolify installation in $SOURCE_DIR is incomplete."
fi

DOCKER_CONFIG_MOUNT=""
if [ -f /root/.docker/config.json ]; then
    DOCKER_CONFIG_MOUNT="-v /root/.docker/config.json:/root/.docker/config.json:ro"
fi

write_status "1" "Pulling release image"
log "Pulling $TARGET"
docker pull "$TARGET" >>"$LOGFILE" 2>&1 || abort "The release image $TARGET could not be pulled."
pull_or_reuse "$YQ_IMAGE" || abort "The configuration tool image could not be pulled."

write_status "2" "Verifying release image"
if [ -n "$SIGNATURE_ISSUER" ] || [ -n "$SIGNATURE_IDENTITY_REGEXP" ]; then
    if [ -z "$SIGNATURE_ISSUER" ] || [ -z "$SIGNATURE_IDENTITY_REGEXP" ]; then
        abort "The signature policy is incomplete."
    fi
    COSIGN_CONFIG=""
    if [ -d /root/.docker ]; then
        COSIGN_CONFIG="-e DOCKER_CONFIG=/docker-config -v /root/.docker:/docker-config:ro"
    fi
    log "Verifying the image signature"
    docker run --rm $COSIGN_CONFIG "$COSIGN_IMAGE" verify \
        --certificate-oidc-issuer "$SIGNATURE_ISSUER" \
        --certificate-identity-regexp "$SIGNATURE_IDENTITY_REGEXP" \
        "$TARGET" >>"$LOGFILE" 2>&1 || abort "The image signature of $TARGET could not be verified."
fi

IDENTITY=$(docker run --rm --entrypoint php "$TARGET" -r '$d = json_decode((string) @file_get_contents("/var/www/html/build.json"), true); if (! is_array($d)) { exit(1); } echo implode("|", [$d["type"] ?? "", $d["distribution"] ?? "", $d["source_commit"] ?? "", $d["build_number"] ?? "", $d["image_repository"] ?? "", $d["base_version"] ?? ""]);' 2>>"$LOGFILE") || abort "The release image has no readable build identity."
EXPECTED_IDENTITY="custom|${DISTRIBUTION}|${COMMIT}|${BUILD}|${IMAGE}|${BASE_VERSION}"
if [ "$IDENTITY" != "$EXPECTED_IDENTITY" ]; then
    log "Image identity: $IDENTITY"
    log "Expected identity: $EXPECTED_IDENTITY"
    abort "The release image does not contain the expected build (commit $COMMIT, build $BUILD)."
fi
log "Image identity verified: $IDENTITY"

mkdir -p "$STAGE_DIR"
BUNDLE_CONTAINER="coolify-distribution-bundle-$$"
docker create --name "$BUNDLE_CONTAINER" "$TARGET" >>"$LOGFILE" 2>&1 || abort "The release bundle could not be extracted."
docker cp "$BUNDLE_CONTAINER:$BUNDLE_DIR/." "$STAGE_DIR" >>"$LOGFILE" 2>&1
COPY_STATUS=$?
docker rm "$BUNDLE_CONTAINER" >>"$LOGFILE" 2>&1 || true
[ "$COPY_STATUS" -eq 0 ] || abort "The release bundle could not be extracted."
for file in docker-compose.yml docker-compose.prod.yml .env.production upgrade-postgres.sh; do
    [ -s "$STAGE_DIR/$file" ] || abort "The release bundle is missing $file."
done

MIGRATION_BEFORE=$(latest_migration)
log "Latest applied migration: ${MIGRATION_BEFORE:-unknown}"
if [ -n "$EXPECTED_MIGRATION" ] && [ -n "$MIGRATION_BEFORE" ] && [[ "$MIGRATION_BEFORE" > "$EXPECTED_MIGRATION" ]]; then
    abort "The database contains migrations that $VERSION does not know ($MIGRATION_BEFORE). Restore a matching database backup first."
fi

write_status "3" "Preparing configuration"
mkdir -p "$BACKUP_DIR"
for file in docker-compose.yml docker-compose.prod.yml docker-compose.custom.yml .env .env.production upgrade-postgres.sh; do
    if [ -f "$SOURCE_DIR/$file" ]; then
        cp -p "$SOURCE_DIR/$file" "$BACKUP_DIR/$file"
    else
        touch "$BACKUP_DIR/.absent-$file"
    fi
done
PREVIOUS_IMAGE_ID=$(docker inspect --format '{{.Image}}' "$APP_CONTAINER" 2>/dev/null || true)
PREVIOUS_REFERENCE=$(docker inspect --format '{{.Config.Image}}' "$APP_CONTAINER" 2>/dev/null || true)
printf 'image_id=%s\nreference=%s\nmigration=%s\n' "$PREVIOUS_IMAGE_ID" "$PREVIOUS_REFERENCE" "$MIGRATION_BEFORE" >"$BACKUP_DIR/previous-image.txt"
if [ -n "$PREVIOUS_IMAGE_ID" ]; then
    docker tag "$PREVIOUS_IMAGE_ID" "coolify-distribution:rollback" >>"$LOGFILE" 2>&1 || true
fi
log "Configuration backup: $BACKUP_DIR"

cp "$STAGE_DIR/docker-compose.yml" "$SOURCE_DIR/docker-compose.yml"
cp "$STAGE_DIR/docker-compose.prod.yml" "$SOURCE_DIR/docker-compose.prod.yml"
cp "$STAGE_DIR/.env.production" "$SOURCE_DIR/.env.production"
cp "$STAGE_DIR/upgrade-postgres.sh" "$SOURCE_DIR/upgrade-postgres.sh"
chmod +x "$SOURCE_DIR/upgrade-postgres.sh"

awk -F '=' '!seen[$1]++' "$ENV_FILE" "$SOURCE_DIR/.env.production" >"$ENV_FILE.tmp" && cat "$ENV_FILE.tmp" >"$ENV_FILE" && rm -f "$ENV_FILE.tmp"
set_env_var "REGISTRY_URL" "$REGISTRY_URL"
set_env_var "COOLIFY_VERSION" "$BASE_VERSION"
update_env_var "PUSHER_APP_ID" "$(openssl rand -hex 32)"
update_env_var "PUSHER_APP_KEY" "$(openssl rand -hex 32)"
update_env_var "PUSHER_APP_SECRET" "$(openssl rand -hex 32)"
update_env_var "PUSHER_BACKEND_PORT" "6001"
if grep -q '^PUSHER_BACKEND_HOST=coolify-realtime$' "$ENV_FILE"; then
    set_env_var "PUSHER_BACKEND_HOST" "127.0.0.1"
fi

if [ ! -f "$SOURCE_DIR/docker-compose.custom.yml" ]; then
    printf 'services:\n  coolify:\n    image: "%s"\n' "$TARGET" >"$SOURCE_DIR/docker-compose.custom.yml"
else
    docker run --rm --user 0:0 -e COOLIFY_TARGET_IMAGE="$TARGET" -v "$SOURCE_DIR:/workdir" -w /workdir "$YQ_IMAGE" \
        -i '.services.coolify.image = strenv(COOLIFY_TARGET_IMAGE)' docker-compose.custom.yml >>"$LOGFILE" 2>&1 || {
        restore_configuration
        abort "docker-compose.custom.yml could not be updated."
    }
fi

RESOLVED_IMAGE=$(compose_value '.services.coolify.image' | tr -d '"')
if [ "$RESOLVED_IMAGE" != "$TARGET" ]; then
    log "Effective coolify image: ${RESOLVED_IMAGE:-none}"
    restore_configuration
    abort "The effective Compose configuration does not select $TARGET."
fi
log "Effective Compose configuration selects $TARGET"

RECREATE_DATA_SERVICES="false"
for pair in "postgres:$DB_CONTAINER" "redis:coolify-redis"; do
    service="${pair%%:*}"
    container="${pair#*:}"
    configured=$(compose_value ".services.$service.image" | tr -d '"')
    running=$(docker inspect --format '{{.Config.Image}}' "$container" 2>/dev/null || true)
    project=$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}' "$container" 2>/dev/null || true)
    if [ -n "$running" ] && { [ "$configured" != "$running" ] || [ "$project" != "source" ]; }; then
        RECREATE_DATA_SERVICES="true"
        log "$container will be recreated (configured: $configured, running: $running, project: ${project:-none})"
    fi
done

pull_or_reuse "${REGISTRY_URL}/coollabsio/coolify-helper:${HELPER_VERSION}" || {
    restore_configuration
    abort "The helper image could not be pulled."
}
compose_files
for image in $(docker compose --env-file "$ENV_FILE" "${COMPOSE_FILES[@]}" config --images 2>>"$LOGFILE" | sort -u); do
    if [ "$image" != "$TARGET" ]; then
        pull_or_reuse "$image" || {
            restore_configuration
            abort "The image $image could not be pulled."
        }
    fi
done

if ! docker network inspect coolify >/dev/null 2>&1; then
    docker network create --attachable --ipv6 coolify >/dev/null 2>&1 || docker network create --attachable coolify >/dev/null 2>&1
fi
mkdir -p /data/coolify/images/avatars /data/coolify/images/project-icons 2>/dev/null || true

CONTEXT_FILE="$STAGE_DIR/switch.context"
{
    for key in UPGRADE_ID IMAGE DIGEST COMMIT BUILD BASE_VERSION VERSION DISTRIBUTION HELPER_VERSION REGISTRY_URL EXPECTED_MIGRATION ROLLBACK LOGFILE BACKUP_DIR STAGE_DIR MIGRATION_BEFORE RECREATE_DATA_SERVICES DOCKER_CONFIG_MOUNT; do
        printf '%s=%s\n' "$key" "${!key}"
    done
} >"$CONTEXT_FILE"

log "Starting the detached switch phase"
if command -v setsid >/dev/null 2>&1; then
    setsid nohup bash "$SCRIPT_PATH" --phase switch --context "$CONTEXT_FILE" >>"$LOGFILE" 2>&1 < /dev/null &
else
    nohup bash "$SCRIPT_PATH" --phase switch --context "$CONTEXT_FILE" >>"$LOGFILE" 2>&1 < /dev/null &
fi
sleep 2
echo "Coolify $VERSION is being started in the background. Log: $LOGFILE"
