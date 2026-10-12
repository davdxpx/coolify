<?php

namespace App\Services\Configuration;

use App\Models\Application;
use App\Models\ConfigurationContract;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\SharedEnvironmentVariable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Configuration state of an environment without any value: changes not deployed yet, shared
 * variables that changed after the last deployment, references to missing shared variables,
 * contract violations and secrets that are due for rotation.
 */
class ConfigurationReport
{
    public const DEFAULT_ROTATION_DAYS = 180;

    public const SECRET_KEY_PATTERN = '/(SECRET|TOKEN|PASSWORD|PASSWD|PRIVATE|API_?KEY|ACCESS_?KEY|CREDENTIAL|SIGNING|ENCRYPTION|_KEY$|^KEY$|DSN)/i';

    public function __construct(private readonly int $rotationDays = self::DEFAULT_ROTATION_DAYS) {}

    /**
     * @return array<string, mixed>
     */
    public function forEnvironment(Environment $environment): array
    {
        $teamId = (int) $environment->project->team_id;
        $edges = (new SharedVariableGraph($teamId))->edges((int) $environment->id);
        $applications = Application::query()->where('environment_id', $environment->id)->with(['environment_variables', 'settings'])->orderBy('name')->get();
        $contracts = ConfigurationContract::query()->whereIn('application_id', $applications->pluck('id'))->get()->keyBy('application_id');

        $resources = $applications->map(fn (Application $application) => $this->forApplication($application, $edges->where('resource_uuid', $application->uuid), $contracts->get($application->id)))->values();

        return [
            'environment_uuid' => $environment->uuid,
            'environment_name' => $environment->name,
            'rotation_days' => $this->rotationDays,
            'summary' => [
                'resources' => $resources->count(),
                'pending_changes' => $resources->where('pending_changes.changed', true)->count(),
                'stale_shared_references' => $resources->sum(fn (array $resource) => count($resource['stale_shared_references'])),
                'missing_shared_references' => $resources->sum(fn (array $resource) => count($resource['missing_shared_references'])),
                'contract_violations' => $resources->sum(fn (array $resource) => $resource['contract'] ? count($resource['contract']['missing']) + count($resource['contract']['empty']) : 0),
                'secrets_due_for_rotation' => $resources->sum(fn (array $resource) => count($resource['secrets_due_for_rotation'])),
            ],
            'resources' => $resources->all(),
            'shared_variables' => $this->sharedVariables($environment, $edges),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $edges
     * @return array<string, mixed>
     */
    public function forApplication(Application $application, Collection $edges, ?ConfigurationContract $contract): array
    {
        try {
            $diff = $application->pendingDeploymentConfigurationDiff();
            $pending = [
                'changed' => $diff->isChanged(),
                'requires_build' => $diff->requiresBuild(),
                'items' => collect($diff->changes())->map(fn (array $change) => [
                    'section' => $change['section_label'] ?? $change['section'] ?? null,
                    'label' => $change['label'] ?? $change['key'] ?? null,
                    'type' => $change['type'] ?? null,
                    'impact' => $change['impact'] ?? null,
                ])->values()->all(),
            ];
        } catch (Throwable) {
            $pending = ['changed' => null, 'requires_build' => null, 'items' => []];
        }

        $variables = $application->environment_variables->where('is_preview', false);

        return [
            'resource_type' => 'application',
            'resource_uuid' => $application->uuid,
            'resource_name' => $application->name,
            'pending_changes' => $pending,
            'stale_shared_references' => $edges->where('stale', true)->map(fn (array $edge) => [
                'variable_key' => $edge['variable_key'],
                'shared' => "{$edge['shared_type']}.{$edge['shared_key']}",
                'shared_updated_at' => $edge['shared_updated_at']?->toIso8601String(),
                'last_deployed_at' => $edge['last_deployed_at']?->toIso8601String(),
            ])->values()->all(),
            'missing_shared_references' => $edges->where('missing', true)->map(fn (array $edge) => [
                'variable_key' => $edge['variable_key'],
                'shared' => "{$edge['shared_type']}.{$edge['shared_key']}",
            ])->values()->all(),
            'contract' => $contract ? $this->checkContract($contract, $variables, $edges) : null,
            'secrets_due_for_rotation' => $this->rotationDue($variables),
        ];
    }

    /**
     * @param  Collection<int, EnvironmentVariable>  $variables
     * @param  Collection<int, array<string, mixed>>  $edges
     * @return array{source: string|null, source_revision: string|null, missing: list<string>, empty: list<string>, unresolved: list<string>, undeclared: list<string>}
     */
    public function checkContract(ConfigurationContract $contract, Collection $variables, Collection $edges): array
    {
        $present = $variables->keyBy('key');
        $required = $contract->requiredKeys();
        $declared = [...$required, ...$contract->optionalKeys()];
        $unresolved = $edges->where('missing', true)->pluck('variable_key')->unique()->values()->all();

        return [
            'source' => $contract->source,
            'source_revision' => $contract->source_revision,
            'missing' => array_values(array_filter($required, fn (string $key) => ! $present->has($key))),
            'empty' => array_values(array_filter($required, fn (string $key) => $present->has($key) && blank($present->get($key)->value))),
            'unresolved' => array_values(array_intersect($required, $unresolved)),
            'undeclared' => array_values(array_diff($present->keys()->all(), $declared)),
        ];
    }

    /**
     * @param  Collection<int, EnvironmentVariable>  $variables
     * @return list<array{key: string, updated_at: string|null, age_days: int|null}>
     */
    public function rotationDue(Collection $variables): array
    {
        $due = [];
        foreach ($variables as $variable) {
            if (! preg_match(self::SECRET_KEY_PATTERN, (string) $variable->key) || SharedVariableGraph::referencesIn($variable->value) !== []) {
                continue;
            }
            $age = $variable->updated_at ? (int) $variable->updated_at->diffInDays(now(), true) : null;
            if ($age !== null && $age >= $this->rotationDays) {
                $due[] = ['key' => (string) $variable->key, 'updated_at' => $variable->updated_at->toIso8601String(), 'age_days' => $age];
            }
        }

        return $due;
    }

    /**
     * Shared variables visible in the environment with the resources that use them.
     *
     * @param  Collection<int, array<string, mixed>>  $edges
     * @return list<array<string, mixed>>
     */
    private function sharedVariables(Environment $environment, Collection $edges): array
    {
        $shared = SharedEnvironmentVariable::query()
            ->where('team_id', $environment->project->team_id)
            ->where(fn ($query) => $query
                ->where('type', 'team')
                ->orWhere(fn ($query) => $query->where('type', 'project')->where('project_id', $environment->project_id))
                ->orWhere(fn ($query) => $query->where('type', 'environment')->where('environment_id', $environment->id)))
            ->orderBy('type')
            ->orderBy('key')
            ->get(['id', 'key', 'type', 'updated_at', 'is_shown_once']);

        return $shared->map(function (SharedEnvironmentVariable $variable) use ($edges) {
            $users = $edges->where('shared_id', $variable->id);
            $age = $variable->updated_at ? (int) $variable->updated_at->diffInDays(now(), true) : null;

            return [
                'id' => $variable->id,
                'reference' => "{$variable->type}.{$variable->key}",
                'updated_at' => $variable->updated_at?->toIso8601String(),
                'used_by' => $users->map(fn (array $edge) => ['resource_uuid' => $edge['resource_uuid'], 'resource_name' => $edge['resource_name'], 'variable_key' => $edge['variable_key'], 'stale' => $edge['stale']])->values()->all(),
                'stale_resources' => $users->where('stale', true)->pluck('resource_name')->unique()->values()->all(),
                'rotation_due' => preg_match(self::SECRET_KEY_PATTERN, (string) $variable->key) === 1 && $age !== null && $age >= $this->rotationDays,
            ];
        })->values()->all();
    }

    /**
     * What a rotation of a shared variable involves: every resource that reads it and must be redeployed afterwards.
     *
     * @return array<string, mixed>
     */
    public function rotationPlan(SharedEnvironmentVariable $variable): array
    {
        $impact = (new SharedVariableGraph((int) $variable->team_id))->impactOf($variable);
        $resources = $impact->groupBy('resource_uuid')->map(fn (Collection $edges) => [
            'resource_type' => $edges->first()['resource_type'],
            'resource_uuid' => $edges->first()['resource_uuid'],
            'resource_name' => $edges->first()['resource_name'],
            'variable_keys' => $edges->pluck('variable_key')->unique()->values()->all(),
            'last_deployed_at' => $edges->first()['last_deployed_at']?->toIso8601String(),
        ])->values();

        return [
            'reference' => "{$variable->type}.{$variable->key}",
            'updated_at' => $variable->updated_at?->toIso8601String(),
            'resources' => $resources->all(),
            'steps' => [
                'Create the new credential at its issuer while the old one stays valid.',
                "Update the shared variable {$variable->type}.{$variable->key}.",
                $resources->isEmpty()
                    ? 'No resource reads this variable; nothing needs to be redeployed.'
                    : 'Redeploy '.$resources->pluck('resource_name')->implode(', ').', for example with a release group that contains them.',
                'Check that every resource reports the new deployment as healthy.',
                'Revoke the old credential at its issuer.',
            ],
        ];
    }
}
