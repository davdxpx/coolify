#!/bin/bash
## Installs a throwaway Coolify under /data/coolify and upgrades it with upgrade-distribution.sh.
## Only for disposable hosts such as CI runners. It refuses to run where Coolify is already installed.

set -euo pipefail

TARGET=""
TARGET_BUILD=""
TARGET_COMMIT=""
TARGET_BASE=""
TARGET_VERSION=""
TARGET_MIGRATION=""
DISTRIBUTION=""
FROM=""
HELPER_VERSION="1.0.17"
REGISTRY="ghcr.io"
SOURCE_DIR="/data/coolify/source"
APP_URL="http://127.0.0.1:8000"
SIGNATURE_ISSUER=""
SIGNATURE_IDENTITY_REGEXP=""
SKIP_FAILURE_INJECTION="false"

while [ $# -gt 0 ]; do
    case "$1" in
    --target) TARGET="$2"; shift 2 ;;
    --target-build) TARGET_BUILD="$2"; shift 2 ;;
    --target-commit) TARGET_COMMIT="$2"; shift 2 ;;
    --target-base) TARGET_BASE="$2"; shift 2 ;;
    --target-version) TARGET_VERSION="$2"; shift 2 ;;
    --target-migration) TARGET_MIGRATION="$2"; shift 2 ;;
    --distribution) DISTRIBUTION="$2"; shift 2 ;;
    --from) FROM="$2"; shift 2 ;;
    --helper-version) HELPER_VERSION="$2"; shift 2 ;;
    --registry) REGISTRY="$2"; shift 2 ;;
    --signature-issuer) SIGNATURE_ISSUER="$2"; shift 2 ;;
    --signature-identity-regexp) SIGNATURE_IDENTITY_REGEXP="$2"; shift 2 ;;
    --skip-failure-injection) SKIP_FAILURE_INJECTION="true"; shift ;;
    *) echo "Unknown argument: $1" >&2; exit 2 ;;
    esac
done

fail() {
    echo "SMOKE TEST FAILED: $1" >&2
    if [ -d "$SOURCE_DIR" ]; then
        find "$SOURCE_DIR" -maxdepth 1 -name 'upgrade-distribution-*.log' -exec tail -n 80 {} \; >&2 || true
    fi
    docker logs --tail 80 coolify >&2 2>/dev/null || true
    exit 1
}

step() {
    echo "==> $1"
}

[ "$(id -u)" -eq 0 ] || fail "Run as root on a disposable host."
[ -n "$TARGET" ] && [ -n "$TARGET_BUILD" ] && [ -n "$TARGET_COMMIT" ] && [ -n "$TARGET_BASE" ] && [ -n "$DISTRIBUTION" ] || fail "Missing target arguments."
if [ -e "$SOURCE_DIR/.env" ] || docker ps -a --format '{{.Names}}' | grep -qx coolify; then
    fail "Coolify is already installed on this host. The smoke test only runs on disposable hosts."
fi

TARGET_IMAGE="${TARGET%@*}"
TARGET_DIGEST="${TARGET#*@}"
START="${FROM:-$TARGET}"

wait_for_health() {
    local attempts=0
    until curl -fsS "$APP_URL/api/health" >/dev/null 2>&1; do
        attempts=$((attempts + 1))
        [ "$attempts" -lt 120 ] || return 1
        sleep 5
    done
}

running_build() {
    docker exec coolify php -r '$d = json_decode((string) @file_get_contents("/var/www/html/build.json"), true); echo is_array($d) ? ($d["build_number"] ?? "") : "";'
}

step "Preparing an isolated installation for $START"
mkdir -p "$SOURCE_DIR" /data/coolify/ssh/keys /data/coolify/ssh/mux /data/coolify/applications /data/coolify/databases /data/coolify/services /data/coolify/backups /data/coolify/images/avatars /data/coolify/images/project-icons
docker pull "$START" >/dev/null
BUNDLE_CONTAINER="coolify-smoke-bundle-$$"
docker create --name "$BUNDLE_CONTAINER" "$START" >/dev/null
docker cp "$BUNDLE_CONTAINER:/usr/local/share/coolify/source/." "$SOURCE_DIR"
docker rm "$BUNDLE_CONTAINER" >/dev/null

cp "$SOURCE_DIR/.env.production" "$SOURCE_DIR/.env"
set_env() {
    if grep -q "^$1=" "$SOURCE_DIR/.env"; then
        sed -i "s|^$1=.*|$1=$2|" "$SOURCE_DIR/.env"
    else
        printf '%s=%s\n' "$1" "$2" >>"$SOURCE_DIR/.env"
    fi
}
set_env APP_ID "$(openssl rand -hex 16)"
set_env APP_KEY "base64:$(openssl rand -base64 32)"
set_env DB_PASSWORD "$(openssl rand -hex 24)"
set_env REDIS_PASSWORD "$(openssl rand -hex 24)"
set_env PUSHER_APP_ID "$(openssl rand -hex 32)"
set_env PUSHER_APP_KEY "$(openssl rand -hex 32)"
set_env PUSHER_APP_SECRET "$(openssl rand -hex 32)"
set_env REGISTRY_URL "$REGISTRY"
set_env COOLIFY_VERSION "$TARGET_BASE"
printf 'services:\n  coolify:\n    image: "%s"\n' "$START" >"$SOURCE_DIR/docker-compose.custom.yml"
chown -R 9999:root /data/coolify/ssh /data/coolify/images

docker network inspect coolify >/dev/null 2>&1 || docker network create --attachable coolify >/dev/null
docker compose --env-file "$SOURCE_DIR/.env" -f "$SOURCE_DIR/docker-compose.yml" -f "$SOURCE_DIR/docker-compose.prod.yml" -f "$SOURCE_DIR/docker-compose.custom.yml" up -d --wait --wait-timeout 300 || fail "The starting version did not become healthy."
wait_for_health || fail "The starting version does not answer /api/health."
echo "Starting build: $(running_build)"

if [ -z "$FROM" ]; then
    [ "$(running_build)" = "$TARGET_BUILD" ] || fail "The running build is not $TARGET_BUILD."
    step "Fresh installation of build $TARGET_BUILD is healthy"
    exit 0
fi

run_upgrade() {
    local upgrade_id="$1" digest="$2" expected_migration="$3"
    local arguments=(--upgrade-id "$upgrade_id" --image "$TARGET_IMAGE" --digest "$digest" --commit "$TARGET_COMMIT" --build "$TARGET_BUILD" --base-version "$TARGET_BASE" --version "${TARGET_VERSION:-build-$TARGET_BUILD}" --distribution "$DISTRIBUTION" --helper-version "$HELPER_VERSION" --registry "$REGISTRY")
    if [ -n "$expected_migration" ]; then
        arguments+=(--expected-migration "$expected_migration")
    fi
    if [ -n "$SIGNATURE_ISSUER" ]; then
        arguments+=(--signature-issuer "$SIGNATURE_ISSUER" --signature-identity-regexp "$SIGNATURE_IDENTITY_REGEXP")
    fi
    docker cp coolify:/usr/local/share/coolify/source/upgrade-distribution.sh "$SOURCE_DIR/upgrade-distribution.sh"
    bash "$SOURCE_DIR/upgrade-distribution.sh" "${arguments[@]}"
}

wait_for_result() {
    local upgrade_id="$1" attempts=0
    while true; do
        if [ -f "$SOURCE_DIR/.upgrade-distribution-result" ] && grep -q "^${upgrade_id}|" "$SOURCE_DIR/.upgrade-distribution-result"; then
            cut -d '|' -f2 "$SOURCE_DIR/.upgrade-distribution-result"
            return 0
        fi
        attempts=$((attempts + 1))
        [ "$attempts" -lt 180 ] || return 1
        sleep 5
    done
}

if [ "$SKIP_FAILURE_INJECTION" != "true" ]; then
    step "Failure injection: an image digest that does not exist must not touch the running version"
    BEFORE_CONTAINER=$(docker inspect --format '{{.Id}}' coolify)
    MISSING_DIGEST="sha256:$(printf '%064d' 0)"
    FAILURE_ID=$(cat /proc/sys/kernel/random/uuid)
    if run_upgrade "$FAILURE_ID" "$MISSING_DIGEST" "$TARGET_MIGRATION"; then
        fail "The upgrade script accepted a missing image."
    fi
    [ "$(cut -d '|' -f1-2 "$SOURCE_DIR/.upgrade-distribution-result")" = "$FAILURE_ID|aborted" ] || fail "A missing image did not produce an aborted result."
    [ "$(docker inspect --format '{{.Id}}' coolify)" = "$BEFORE_CONTAINER" ] || fail "The running container changed after a failed pull."
    wait_for_health || fail "Coolify is not healthy after the aborted upgrade."
fi

step "Upgrading to build $TARGET_BUILD ($TARGET)"
UPGRADE_ID=$(cat /proc/sys/kernel/random/uuid)
run_upgrade "$UPGRADE_ID" "$TARGET_DIGEST" "$TARGET_MIGRATION" || fail "The upgrade script refused the release."
RESULT=$(wait_for_result "$UPGRADE_ID") || fail "The upgrade did not report a result."
[ "$RESULT" = "completed" ] || fail "The upgrade finished with $RESULT."
wait_for_health || fail "The upgraded version does not answer /api/health."

RUNNING_DIGESTS=$(docker image inspect --format '{{json .RepoDigests}}' "$(docker inspect --format '{{.Image}}' coolify)")
echo "$RUNNING_DIGESTS" | grep -q "$TARGET_DIGEST" || fail "The running image is not $TARGET_DIGEST ($RUNNING_DIGESTS)."
[ "$(running_build)" = "$TARGET_BUILD" ] || fail "The running build is not $TARGET_BUILD."
grep -q "image: \"\{0,1\}$TARGET\"\{0,1\}" "$SOURCE_DIR/docker-compose.custom.yml" || fail "docker-compose.custom.yml does not pin $TARGET."
grep -q "^COOLIFY_VERSION=$TARGET_BASE$" "$SOURCE_DIR/.env" || fail "COOLIFY_VERSION was not set to $TARGET_BASE."

step "Upgrade to build $TARGET_BUILD verified"
