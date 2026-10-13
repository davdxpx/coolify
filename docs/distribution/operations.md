# Operating a custom distribution

## Lifecycle of a release

```text
push to main
  └─ Distribution Build
       ├─ Distribution CI (Pest suite, ShellCheck, tooling lint)
       ├─ build linux/amd64 + linux/aarch64 with the build identity
       ├─ publish the image index, sign it (cosign keyless), attest provenance
       ├─ verify: signature, build.json inside the image, bundled files
       ├─ smoke test on the runner: fresh install, or upgrade from the current
       │  development build including a failure injection
       └─ manifest: build N on the development channel
Distribution Promote (manual)
  ├─ staging: same digest, tag :staging, manifest channel staging = N
  └─ stable:  only after staging, GitHub Environment approval,
              tag :<version> and :stable, Git tag v<version>, GitHub release,
              manifest channel stable = N with the release label
Coolify (Settings → Updates)
  ├─ sees the verified newer build on its channel
  ├─ runs the update checks, the admin reviews and confirms
  ├─ the host script pulls by digest, verifies, pins, restarts Coolify only
  └─ the new container verifies build, commit and digest and records the result
```

Nothing on `main` installs itself on a production instance. The default channel is `stable`, automatic installation is off, and stable promotion needs approval.

## Image identity

| Item | Where it comes from |
| --- | --- |
| Image | `ghcr.io/<owner>/<repo>` in lowercase, for this fork `ghcr.io/davdxpx/coolify` |
| Base version | `config/constants.php` (`bootstrap/getVersion.php`) |
| Build number | `github.run_number` of Distribution Build |
| Commit | `github.sha` |
| Manifest URL | `https://raw.githubusercontent.com/<owner>/<repo>/distribution/manifest.json` |
| Signature policy | issuer `https://token.actions.githubusercontent.com`, identity `https://github.com/<owner>/<repo>/.github/workflows/distribution-build.yml@refs/heads/main` |

`ghcr.io/davdxpx/coolify` is the right path: the workflows publish with the repository's `GITHUB_TOKEN`, which can write packages linked to the same repository, and the path does not collide with `ghcr.io/coollabsio/coolify`.

The Dockerfile writes `build.json` only when `COOLIFY_DISTRIBUTION` is set, so official builds stay unchanged. It also places `docker-compose.yml`, `docker-compose.prod.yml`, `.env.production`, `upgrade-postgres.sh` and `upgrade-distribution.sh` in `/usr/local/share/coolify/source/`. The upgrade script installs these files from the new image instead of downloading them from the official CDN.

## Tags

| Tag | Meaning |
| --- | --- |
| `build-<n>-amd64`, `build-<n>-aarch64` | Per-architecture build output |
| `<base>-dev.<n>` | Development build `n` |
| `sha-<commit>` | Build of an exact commit |
| `staging`, `stable` | Moving pointers set by promotion |
| `<base>-<label>.<n>` | A stable release, for example `4.4.6-fork.1` |

Coolify never installs by tag. The manifest and the Compose pin always use the digest.

## Day-to-day

- **Merge to `main`** only through pull requests that passed Distribution CI.
- **Promote** with Actions → Distribution Promote: enter the build number from the manifest, channel `staging`, later `stable` with a version such as `4.4.6-fork.1` and a short summary.
- **Watch** Settings → Updates on the test installation (channel `staging` or `development`) before promoting to stable.
- **Install** on production from Settings → Updates → Upgrade now, after reading the checks.
- **Keep the official helper images reachable**: the host pulls `coollabsio/coolify-helper` from the registry chosen in Settings → Updates → Image registry.

## Isolated test installation

Run the smoke test on a disposable machine (a VM or a cloud runner, never the production host). It refuses to run where `/data/coolify/source/.env` or a `coolify` container exists.

```bash
sudo bash scripts/distribution/upgrade-smoke-test.sh \
  --from ghcr.io/davdxpx/coolify@sha256:<current> \
  --target ghcr.io/davdxpx/coolify@sha256:<candidate> \
  --target-build <n> --target-commit <sha> --target-base 4.4.6 \
  --target-version 4.4.6-dev.<n> --distribution davdxpx/coolify \
  --target-migration <latest migration name> \
  --signature-issuer https://token.actions.githubusercontent.com \
  --signature-identity-regexp '^https://github\.com/davdxpx/coolify/\.github/workflows/distribution-build\.yml@refs/heads/main$'
```

It installs the starting version with its own Postgres and Redis, injects a failed update (missing digest) and checks that the running container is untouched, upgrades with the real script, and verifies digest, build, Compose pin and `COOLIFY_VERSION`. Distribution Build runs the same script on every build.

For login, UI, API, MCP, queues and the deployment queue, use a separate test Coolify on its own VM with the `staging` channel, add a throwaway server, and deploy a sample application before you promote.

## Retention

- GHCR keeps every pushed digest. Do not run `cleanup-ghcr-untagged` in the fork: the per-architecture manifests of a published index are untagged.
- The manifest keeps the newest 100 releases plus every release a channel points to.
- The host keeps the previous image as `coolify-distribution:rollback` and configuration backups in `/data/coolify/source/backups/distribution-upgrade-<date>/`.
