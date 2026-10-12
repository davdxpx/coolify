# Configuration intelligence

Open it from an environment: **Configuration** next to *Releases*. The page and the API answer, for every application of the environment, what changed and is not deployed yet. They never show or return a variable value: values are decrypted only in memory to find references and to tell empty from set.

## What is reported

| Finding | Meaning |
| --- | --- |
| Not deployed | The application's configuration differs from the snapshot of its last successful deployment (Coolify's existing configuration diff): which settings changed and whether a rebuild or only a redeploy is needed. |
| Shared variable changed after the last deployment | A variable of the application refers to a shared variable (`{{team.KEY}}`, `{{project.KEY}}`, `{{environment.KEY}}`, `{{server.KEY}}`) that was changed after the start of the last successful deployment. The configuration diff cannot see this, because the application stores the reference, not the value. |
| Reference to a missing shared variable | The referenced shared variable does not exist in the scope that applies to the application. |
| Contract | Required keys that are missing or empty, required keys whose shared reference is missing, and keys that are set but not declared. |
| Rotation due | Variables whose key looks like a secret (`SECRET`, `TOKEN`, `PASSWORD`, `API_KEY`, `DSN`, ...) and that were not changed for the rotation period (180 days, `rotation_days` in the API). References to shared variables are judged on the shared variable. |

The list of shared variables shows, for team, project and environment variables of this environment, which applications use them and which of those still run an older value.

## Contracts

A contract declares which keys an application needs, typically generated from the repository that builds it (for example from `.env.example`). Only keys are sent.

```http
PUT /api/v1/applications/{uuid}/configuration-contract
{"required_keys": ["DATABASE_URL", "SESSION_SECRET"], "optional_keys": ["SENTRY_DSN"], "source": "repo:apps/web/.env.example", "source_revision": "4f2c1e0"}
```

Setting or deleting a contract needs admin rights on the application (`write` ability) and is audited. A release of a group that contains the application fails its `configuration` check while a required key is missing or empty, or a variable refers to a missing shared variable; the check can be overridden with an audited reason.

## Rotation plan

`GET /api/v1/configuration/shared-variables/{id}/rotation-plan` lists every application that reads a shared variable and the steps of a rotation without downtime: create the new credential, update the shared variable, redeploy the listed applications (a release group that contains them does it in order), verify, revoke the old credential. Coolify does not rotate credentials itself.

## API

| Method | Path | Ability |
| --- | --- | --- |
| GET | `/api/v1/configuration/environments/{uuid}` | read |
| GET | `/api/v1/configuration/shared-variables/{id}/rotation-plan` | read |
| GET, PUT, DELETE | `/api/v1/applications/{uuid}/configuration-contract` | read, write |

## Source map

`app/Services/Configuration/SharedVariableGraph.php`, `ConfigurationReport.php`, `app/Models/ConfigurationContract.php`, `app/Http/Controllers/Api/ConfigurationController.php`, `app/Livewire/Project/Configuration/Index.php`, the `configuration` check in `app/Services/Release/ReleasePreflight.php`.

## Limits

- Services are included in the reference graph; drift of services and databases (configuration not deployed) is only reported for applications, because Coolify keeps deployment snapshots only for applications.
- "Changed after the last deployment" compares timestamps. A shared variable saved with the same value also counts as changed.
