# Following official Coolify

The fork stays close to `coollabsio/coolify` by merging official releases into `main` through pull requests. Fork changes live in additive files where possible; changes to shared files are small and documented in [README.md](README.md).

## Automatic detection

`distribution-upstream-sync.yml` runs every six hours and on demand:

1. Fetches `coollabsio/coolify` (`upstream` remote) and reads the latest official release.
2. Counts the upstream commits on `main` that the fork has not merged and finds the last common commit.
3. Test-merges the latest release tag into a branch `upstream-sync/<tag>`.
4. Without conflicts: pushes the branch and opens (or reuses) the pull request `chore(upstream): sync <tag>`.
5. With conflicts: opens or updates the issue `Upstream sync <tag>: merge conflicts` with the files and the commands to resolve them.
6. Reads published security advisories of the upstream repository with their first patched version.
7. Writes everything into the `upstream` section of the manifest. Coolify shows it under Settings → Updates → Official Coolify, including advisories fixed in a newer official release than the running base.

It never merges and never publishes images. Run it manually with an explicit `ref` (for example `main` or `v4.5.0`) to test a different target.

## Taking over a release

1. Review the sync pull request (or resolve the conflicts locally, see below).
2. Wait for Distribution CI. Do not merge on red.
3. Merge. Distribution Build publishes a development build whose base version is the new official version.
4. Install it on the test installation (`development` or `staging` channel) and check the changes that touched fork areas.
5. Promote to `staging`, then to `stable` with a version such as `4.4.7-fork.1`.
6. Install on production from Settings → Updates.

Security releases: run the sync manually right after the advisory, review the pull request first, and keep the soak time on staging short but not zero.

## Resolving conflicts locally

```bash
git remote add upstream https://github.com/coollabsio/coolify.git   # once
git fetch upstream --tags
git checkout -b upstream-sync/v4.4.7 origin/main
git merge v4.4.7
# resolve, keeping the upstream behaviour and re-applying the fork change
vendor/bin/pint --dirty
php artisan test --compact tests/Feature/Distribution
git commit
git push -u origin upstream-sync/v4.4.7
```

Typical conflict points are the few shared files the fork touches: `app/Actions/Server/UpdateCoolify.php`, `app/Jobs/CheckForUpdatesJob.php`, `app/Jobs/UpdateCoolifyJob.php`, `app/Livewire/Upgrade.php` and its view, `app/Livewire/Settings/Updates.php` and its view, `app/Console/Kernel.php`, `app/Console/Commands/Init.php`, `app/Actions/Server/CleanupDocker.php`, `app/Providers/AppServiceProvider.php` and `docker/production/Dockerfile`. `config/constants.php` and `versions.json` are not modified by the fork, so version bumps merge cleanly.

## Keeping divergence low

- Do not bump `config/constants.php`; the base version must stay the official one.
- Prefer new classes over edits to upstream classes; keep edits to guards and wiring.
- Propose generic parts upstream (build identity, custom distribution channels, signed digests) so they stop being fork changes.
- Keep the upstream workflows unmodified and disabled in the fork instead of editing them.
