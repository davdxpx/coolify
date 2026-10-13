# Custom distributions

A custom distribution is a fork of Coolify that builds, signs and installs its own images while it keeps following the official releases. This directory describes how the fork distribution system works and how to run it.

| Document | Covers |
| --- | --- |
| [operations.md](operations.md) | Building, publishing and operating your own Coolify image |
| [update-channels.md](update-channels.md) | Which update source controls what, and how the wrong distribution is kept out |
| [ci.md](ci.md) | GitHub workflows, permissions, signing and verification |
| [upstream-sync.md](upstream-sync.md) | Taking over official releases, resolving conflicts, releasing |
| [migration.md](migration.md) | Moving an existing official installation to a custom distribution |
| [recovery.md](recovery.md) | Rollback limits, database compatibility and disaster recovery |
| [activation-checklist.md](activation-checklist.md) | What the owner configures or approves after the merge |

## Design in one page

- **The image proves what runs.** A distribution build writes `/var/www/html/build.json` (distribution, official base version, build number, full commit, image repository, manifest URL, signature policy). `COOLIFY_VERSION` in `.env` is never treated as proof. Coolify reads the running image digest with `docker inspect` on the host.
- **Two separate update sources.** The official CDN (`versions.json`) still drives helper, Sentinel and Traefik versions and reports the latest official release. On a custom distribution it can no longer install anything. Coolify releases come from the distribution manifest (`manifest.json` on the `distribution` branch of the fork).
- **Versions never mix.** The official base version (for example `4.4.6`) is compared with `version_compare` only after it is validated as an official version. Fork releases are ordered by an integer build number. A release label such as `4.4.6-fork.3` is only a label.
- **Digests, not tags.** Every manifest release names an image digest from the repository baked into the running build. Coolify refuses any other repository, and the host script pulls by digest, checks the signature against the policy of the running build, and reads `build.json` inside the new image before switching.
- **The pin survives official tools.** The upgrade script pins `services.coolify.image` in `/data/coolify/source/docker-compose.custom.yml` (comment-preserving edit with a pinned `yq` image, other keys untouched). Even the official `upgrade.sh` or `install.sh` keeps that pin because they always include `docker-compose.custom.yml`.
- **Updates are durable and verified.** Each attempt is a row in `coolify_upgrades` with its preflight results. The new container verifies build, commit and digest at boot (`app:init`) and the scheduler reconciles every minute while an update runs.
- **No false rollback promise.** An image rollback is offered only while no migration ran since the update. Otherwise the documented database restore applies.

## Source map

| Area | Files |
| --- | --- |
| Build identity | `docker/production/write-build-identity.php`, `app/Services/Distribution/BuildIdentity.php` |
| Manifest | `app/Services/Distribution/DistributionManifest*.php`, `DistributionRelease.php`, `UpstreamStatus.php`, `scripts/distribution/manifest.php` |
| Update state | `app/Services/Distribution/CoolifyDistribution.php`, `DistributionUpdateState.php`, `RunningImageInspector.php` |
| Preflight | `app/Services/Distribution/DistributionUpgradePreflight.php`, `app/Services/Preflight/PreflightResult.php`, `app/Services/Registry/OciRegistryClient.php` |
| Upgrade | `app/Actions/Distribution/StartDistributionUpgrade.php`, `scripts/upgrade-distribution.sh` |
| Verification | `app/Actions/Distribution/ReconcileDistributionUpgrades.php`, `app/Models/CoolifyUpgrade.php` |
| Official path guards | `app/Actions/Server/UpdateCoolify.php`, `app/Jobs/UpdateCoolifyJob.php`, `app/Jobs/CheckForUpdatesJob.php` |
| UI | `app/Livewire/Upgrade.php`, `app/Livewire/Settings/Updates.php` and their views |
| Workflows | `.github/workflows/distribution-*.yml` |
| Host tools | `scripts/distribution/inventory.sh`, `scripts/distribution/upgrade-smoke-test.sh` |
