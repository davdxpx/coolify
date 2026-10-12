# Activation checklist

Everything here is a deliberate owner action. Nothing in this repository activates it on its own.

## GitHub repository

- [ ] **Disable the upstream workflows in the fork** (Actions → workflow → ⋯ → Disable workflow): Build Coolify Main, Build Coolify Next, Build Coolify Release Branch, Release Coolify RC, Release Coolify Stable, Coolify Helper (both), Coolify Testing Host, Cleanup Untagged GHCR Images, Sync main to release branches, Generate Changelog, PR Quality, PR comments, Manage PR branch, Manage stale issues, Lock closed issues. They target `coollabsio` registries and secrets, fail on every push, and `Cleanup Untagged GHCR Images` would delete the untagged per-architecture manifests of published distribution images. They stay unmodified in the code so upstream merges stay clean.
- [ ] Keep these enabled: Distribution CI, Distribution Build, Distribution Promote, Distribution Upstream Sync.
- [ ] Create the environment `distribution-stable` with yourself as required reviewer (Settings → Environments). Optionally `distribution-staging`.
- [ ] After the first successful Distribution Build, protect the branch `distribution` so only GitHub Actions can push (rule: restrict pushes, allow `github-actions[bot]`).
- [ ] Optional: add the secret `DISTRIBUTION_SYNC_TOKEN` (fine-grained token, this repository, contents and pull requests read/write) so upstream sync pull requests start CI automatically.
- [ ] Decide the package visibility of `ghcr.io/davdxpx/coolify` (Packages → coolify → Package settings). Public is simplest. If private, log in on every host that installs it: `docker login ghcr.io -u <user>` with a token that can only read packages.

## First build

- [ ] Merge the pull request. Distribution Build runs on `main`.
- [ ] Check the run: CI, build, publish, signature, image identity, smoke test, manifest.
- [ ] Open `https://raw.githubusercontent.com/davdxpx/coolify/distribution/manifest.json` and find the build on `development`.

## Test installation

- [ ] Prepare a separate VM (not reos-01) with Docker, install the development build following [migration.md](migration.md) on a fresh official installation, or with `scripts/distribution/upgrade-smoke-test.sh` for a throwaway check.
- [ ] Set its channel to `development` (Settings → Updates → Channel).
- [ ] Verify login, dashboard, API with a read token, MCP, a test server, a deployment through the queue, a backup, and Settings → Updates (running digest matches release).
- [ ] Promote the build to `staging` (Actions → Distribution Promote), switch the test installation to `staging`, install through Settings → Updates → Upgrade now, and confirm the update history shows *Succeeded*.
- [ ] Rehearse a restore of a production `coolify-db` backup on the test VM ([recovery.md](recovery.md), restore rehearsal).

## Production migration (separate approval)

- [ ] Promote the tested build to `stable` with a version such as `4.4.6-fork.1`.
- [ ] Schedule a window without deployments.
- [ ] Run the read-only inventory on the host and keep the output.
- [ ] Take and verify a fresh `coolify-db` backup.
- [ ] Follow [migration.md](migration.md) (and the host-specific runbook of the instance).
- [ ] Complete the verification table of [migration.md](migration.md).

## After the migration

- [ ] Leave Settings → Updates → Install distribution releases automatically **disabled** until several manual updates went well.
- [ ] Keep the channel on `stable`.
- [ ] Watch the upstream sync pull requests and advisories under Settings → Updates → Official Coolify.
