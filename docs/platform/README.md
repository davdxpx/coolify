# Platform features of this distribution

Generic features that this fork adds on top of Coolify. None of them depends on a specific product or customer; they work for every team on the instance.

| Document | Covers |
| --- | --- |
| [deployment-lifecycle.md](deployment-lifecycle.md) | Execution phases of a deployment, slot reservation, stalled deployments |
| [releases.md](releases.md) | Release groups: deploying several applications together, in order, with failure policies |
| [deployment-controls.md](deployment-controls.md) | Deployment freezes, release checks with audited overrides, deployed images, build capacity |
| [configuration.md](configuration.md) | Undeployed changes, shared variable impact, contracts, rotation planning |
| [compose-zero-downtime.md](compose-zero-downtime.md) | Zero-downtime (blue/green) deployments for Docker Compose applications |
| [events-and-read-api.md](events-and-read-api.md) | Event feed, signed event webhooks, overview and metrics API, rollback candidates, backup freshness and restore evidence, MCP tools |

The fork distribution itself (images, update channels, upgrades) is described in [../distribution](../distribution/README.md).
