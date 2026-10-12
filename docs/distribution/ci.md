# Distribution workflows

| Workflow | Trigger | Purpose | Permissions |
| --- | --- | --- | --- |
| `distribution-ci.yml` | Pull requests to `main` and `release/v*`, called by the build | Pest suite (`tests/Unit`, `tests/Feature`, `tests/v4/Feature`), ShellCheck, PHP lint of the tooling, migration marker | `contents: read` |
| `distribution-build.yml` | Push to `main`, manual | CI gate, multi-architecture build with build identity, publish, sign, attest, verify, smoke test, manifest entry on `development` | per job: `packages: write`, `id-token: write`, `attestations: write`, `contents: write` only for the manifest job |
| `distribution-promote.yml` | Manual | Re-verify the signature, tag the existing digest, Git tag and release for stable, move the channel | `packages: write`, `contents: write`; runs in the `distribution-staging` or `distribution-stable` environment |
| `distribution-upstream-sync.yml` | Every 6 hours, manual | Detect official releases, test-merge, open a sync pull request or a conflict issue, collect advisories, publish the upstream status | `contents: write`, `pull-requests: write`, `issues: write` |

All workflows start from `permissions: {}` and grant permissions per job. No workflow uses third-party secrets; signing is keyless through the GitHub OIDC token. Pull requests never publish images.

## Gates before an image becomes installable

1. Distribution CI passed on the exact commit (the build job `needs` it).
2. The multi-architecture index was published and its digest resolved.
3. The digest is signed by `distribution-build.yml@refs/heads/main` and the signature verifies.
4. `build.json` inside the image matches distribution, commit, build, base and repository; the upgrade bundle files are present.
5. The fresh-install or upgrade smoke test passed.
6. Only then the manifest job records the build on `development` with all checks. A failed build is never added.
7. Promotion to `stable` requires the build to have been on `staging` and the `distribution-stable` environment approval.

Coolify additionally refuses a release whose verification is not `passed` or whose check list contains a non-successful check.

## Signing and verification

- `cosign sign --yes <image>@<digest>` in the publish job (keyless, Sigstore public good instance).
- `actions/attest-build-provenance` pushes a SLSA provenance attestation to the registry. Check it with `gh attestation verify oci://ghcr.io/davdxpx/coolify@sha256:<digest> --repo davdxpx/coolify`.
- Coolify bakes the expected issuer and identity into `build.json`. The host script verifies every new image against the policy of the **running** build with a pinned cosign image, so a compromised manifest cannot point to an image signed by another workflow or repository.
- The promote workflow re-verifies the signature before it moves a channel.

## Upstream workflows in the fork

The official repository's workflows (`coolify-main-build.yml`, release, helper, testing host, `cleanup-ghcr-untagged.yml`, `sync-main-to-release.yml`, changelog and community automation) target `coollabsio` resources and secrets. In the fork they fail or do unrelated work. They are left unmodified to keep upstream merges clean; disable them in the fork under Actions (see [activation-checklist.md](activation-checklist.md)).

## Secrets and settings

| Name | Required | Use |
| --- | --- | --- |
| `GITHUB_TOKEN` | automatic | Registry, manifest branch, releases, issues |
| `DISTRIBUTION_SYNC_TOKEN` | optional | Fine-grained token with contents and pull request write access. Without it, sync pull requests opened by `GITHUB_TOKEN` do not start Distribution CI; push an empty change or re-run CI manually |
| Environment `distribution-stable` | yes | Required reviewers for stable promotion |
| Environment `distribution-staging` | optional | Created on first use |
| Branch `distribution` | created by the first build | Holds `manifest.json`; protect it so only GitHub Actions can push |
