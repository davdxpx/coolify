#!/bin/bash
## Applies one manifest change on the distribution branch and pushes it. Used by the distribution workflows.

set -euo pipefail

ACTION="${1:-}"
TOOL="$(cd "$(dirname "$0")" && pwd)/manifest.php"
BRANCH="${MANIFEST_BRANCH:-distribution}"
: "${GH_TOKEN:?GH_TOKEN is required}"
: "${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}"
: "${DISTRIBUTION:?DISTRIBUTION is required}"
: "${IMAGE:?IMAGE is required}"

REMOTE="https://x-access-token:${GH_TOKEN}@github.com/${GITHUB_REPOSITORY}.git"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

apply_change() {
    case "$ACTION" in
    add-release)
        php "$TOOL" add-release manifest.json \
            --build "$BUILD" --version "$VERSION" --base-version "$BASE" --commit "$GITHUB_SHA" \
            --image "$IMAGE" --digest "$DIGEST" --channel development --checks "$CHECKS" \
            --verification-url "$RUN_URL" --latest-migration "${MIGRATION:-}" --migration-count "${MIGRATION_COUNT:-}"
        echo "Publish build ${BUILD} (${DIGEST}) on development"
        ;;
    promote)
        local arguments=(--build "$BUILD" --channel "$CHANNEL")
        [ -n "${VERSION:-}" ] && arguments+=(--version "$VERSION")
        [ -n "${NOTES_URL:-}" ] && arguments+=(--notes-url "$NOTES_URL")
        [ -n "${SUMMARY:-}" ] && arguments+=(--summary "$SUMMARY")
        php "$TOOL" promote manifest.json "${arguments[@]}"
        echo "Promote build ${BUILD} to ${CHANNEL}"
        ;;
    set-upstream)
        php "$TOOL" set-upstream manifest.json --json "$UPSTREAM_JSON"
        echo "Update upstream status"
        ;;
    *)
        echo "Unknown action: $ACTION" >&2
        exit 2
        ;;
    esac
}

for attempt in 1 2 3 4 5; do
    rm -rf "$WORK/manifest"
    if git ls-remote --exit-code --heads "$REMOTE" "$BRANCH" >/dev/null 2>&1; then
        git clone --quiet --depth 1 --branch "$BRANCH" "$REMOTE" "$WORK/manifest"
    else
        git init --quiet -b "$BRANCH" "$WORK/manifest"
        git -C "$WORK/manifest" remote add origin "$REMOTE"
    fi

    cd "$WORK/manifest"
    git config user.name "github-actions[bot]"
    git config user.email "41898282+github-actions[bot]@users.noreply.github.com"
    php "$TOOL" init manifest.json --distribution "$DISTRIBUTION" --image "$IMAGE"
    MESSAGE=$(apply_change)
    git add manifest.json
    if git diff --cached --quiet; then
        echo "The manifest is already up to date."
        exit 0
    fi
    git commit --quiet -m "$MESSAGE"
    if git push --quiet origin "HEAD:${BRANCH}"; then
        echo "$MESSAGE"
        exit 0
    fi
    cd /
    sleep $((attempt * 3))
done

echo "The manifest could not be pushed after 5 attempts." >&2
exit 1
