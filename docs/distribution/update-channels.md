# Update channels

## Who controls what

| Concern | Official installation | Custom distribution |
| --- | --- | --- |
| Coolify image | Official CDN `versions.json` → `coolify.v4.version`, installed by the CDN `upgrade.sh` | Distribution manifest, installed by `upgrade-distribution.sh` from the running image |
| Helper image (`coollabsio/coolify-helper`) | `versions.json` → `coolify.helper.version` (`CheckHelperImageJob`) | Unchanged, same source |
| Sentinel | `versions.json` → `coolify.sentinel` | Unchanged |
| Traefik versions | `versions.json` → `traefik` (`CheckTraefikVersionJob`) | Unchanged |
| Service templates, changelog | CDN | Unchanged |
| "Update available" badge | official version newer than running | verified newer fork build on the selected channel |
| Automatic updates | `is_auto_update_enabled` (and `AUTOUPDATE`) | `is_distribution_auto_update_enabled`, off by default; `AUTOUPDATE` has no effect |

`VERSIONS_URL`, `UPGRADE_SCRIPT_URL` and `REGISTRY_URL` are **not** changed for a custom distribution. Overriding them globally would break helper, Sentinel and Traefik updates. `REGISTRY_URL` (Settings → Updates → Image registry) keeps choosing where the official helper images come from.

## The two channels in Settings → Updates

On a custom distribution, Settings → Updates shows:

- **Running version**: distribution, release label, build, official base, commit, the digest of the running container and whether it matches the manifest, the image repository, and whether signatures are required. A warning appears when `COOLIFY_VERSION` in `.env` disagrees with the build (for example after the official upgrade script ran).
- **Distribution updates**: the channel (`stable`, `staging`, `development`), the channel release with CI status, migrations, digest and notes, the reason why a release is not installable, and the **Upgrade now** button. The button opens the shared upgrade dialog, which first runs the update checks.
- **Official Coolify**: latest official release, the base of this build, "Behind upstream", the upstream sync state with conflicts and pull request, and published security advisories fixed in a newer official release.
- **Previous version**: rollback availability and the reason.
- **Update history**: every update attempt with result and message.
- **Automatic updates**: the opt-in switch, the frequency, and the maximum backup age for updates with migrations.

An official installation keeps the previous page and behaviour.

## How the wrong distribution is kept out

| Path | Protection |
| --- | --- |
| Upgrade button, `UpdateCoolify` action | Refuses with an error on a custom build (`DistributionUpgradeRefused`) before any download |
| `UpdateCoolifyJob` (automatic) | Routes to `RunAutomaticDistributionUpdate`, which needs the separate opt-in and acknowledges nothing |
| `CheckForUpdatesJob` | Still updates `versions.json` for helper, Sentinel and Traefik, but `new_version_available` reflects only the distribution channel |
| `CheckHelperImageJob`, Traefik and Sentinel checks | Unchanged and independent |
| Manual `curl … upgrade.sh \| bash` on the host | The official script includes `docker-compose.custom.yml`, so the pinned distribution image stays. It rewrites `COOLIFY_VERSION`; Coolify then shows the version mismatch warning |
| Official `install.sh` | Same Compose override applies. Do not run the installer on an existing host |
| Docker cleanup | Never removes the running image; the previous image kept as `coolify-distribution:rollback` is excluded |
| Manifest | Must belong to the distribution and the image repository baked into the running build; tags are not accepted, only digests |
| Release image | Pulled by digest, signature checked against the running build's policy, `build.json` inside must match commit, build, base and repository |

Switching an installation from the official distribution to a custom one, or back, is a separate migration (see [migration.md](migration.md)); Coolify never does it.

## Version strategy

- **Official base version**: `config/constants.php` of the fork, unchanged from upstream so merges stay conflict-free. Inside a distribution image the build value is authoritative; `AppServiceProvider` applies it to `constants.coolify.version` and keeps the environment value as `constants.coolify.configured_version`.
- **Build number**: the run number of the Distribution Build workflow. Strictly increasing. It orders fork releases.
- **Release label**: `<base>-dev.<build>` for development builds; `<base>-<label>.<n>` (for example `4.4.6-fork.1`) assigned when a build is promoted to stable. Labels are never compared.
- **Commit**: full 40-character SHA in `build.json`, the image label `org.opencontainers.image.revision`, and the manifest.
- **Digest**: the multi-architecture image index digest. The manifest, the Compose pin and the update history store it.
- **Channel**: a pointer from `stable`, `staging` or `development` to a build number.

An update is offered only when the target build number is higher, its official base is not older, and CI verification passed. A rollback uses the separate rollback action and its schema check.
