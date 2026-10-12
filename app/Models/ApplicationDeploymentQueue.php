<?php

namespace App\Models;

use App\Casts\EncryptedArrayCast;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\DeploymentPhase;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Project model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer'],
        'application_id' => ['type' => 'string'],
        'deployment_uuid' => ['type' => 'string'],
        'pull_request_id' => ['type' => 'integer'],
        'docker_registry_image_tag' => ['type' => 'string', 'nullable' => true],
        'configuration_hash' => ['type' => 'string', 'nullable' => true],
        'configuration_snapshot' => ['type' => 'object', 'nullable' => true],
        'configuration_diff' => ['type' => 'object', 'nullable' => true],
        'force_rebuild' => ['type' => 'boolean'],
        'commit' => ['type' => 'string'],
        'status' => ['type' => 'string'],
        'is_webhook' => ['type' => 'boolean'],
        'is_api' => ['type' => 'boolean'],
        'created_at' => ['type' => 'string'],
        'updated_at' => ['type' => 'string'],
        'logs' => ['type' => 'string'],
        'current_process_id' => ['type' => 'string'],
        'restart_only' => ['type' => 'boolean'],
        'git_type' => ['type' => 'string'],
        'server_id' => ['type' => 'integer'],
        'application_name' => ['type' => 'string'],
        'server_name' => ['type' => 'string'],
        'deployment_url' => ['type' => 'string'],
        'destination_id' => ['type' => 'string'],
        'only_this_server' => ['type' => 'boolean'],
        'parent_deployment_uuid' => ['type' => 'string', 'nullable' => true],
        'rollback' => ['type' => 'boolean'],
        'commit_message' => ['type' => 'string'],
        'phase' => ['type' => 'string', 'nullable' => true, 'enum' => ['queued', 'admitted', 'dispatched', 'preparing', 'building', 'pushing', 'deploying', 'verifying', 'finished', 'failed', 'cancelled'], 'description' => 'Execution phase. Null for deployments created before phases were recorded. The status field keeps its previous meaning: in_progress means a deployment slot is reserved.'],
        'phase_changed_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
        'artifacts' => ['type' => 'object', 'nullable' => true, 'description' => 'Images the finished deployment runs: per container the image, its local image id and, when known, its registry digest.'],
        'phase_history' => ['type' => 'array', 'nullable' => true, 'items' => ['type' => 'object', 'properties' => ['phase' => ['type' => 'string'], 'at' => ['type' => 'string', 'format' => 'date-time']]]],
        'admitted_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true, 'description' => 'When a deployment slot was reserved.'],
        'dispatched_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true, 'description' => 'When the job was handed to the queue.'],
        'started_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true, 'description' => 'When a worker actually started the deployment.'],
        'finished_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
    ],
)]
class ApplicationDeploymentQueue extends Model
{
    /**
     * Kept in memory only, never saved.
     *
     * @var array<array-key, mixed>
     */
    private array $remoteSecretsForRedaction = [];

    protected static function booted(): void
    {
        static::created(function (ApplicationDeploymentQueue $deployment): void {
            if (! auth()->check() || ! $deployment->rollback) {
                return;
            }

            $application = $deployment->application;
            $source = $deployment->is_api ? 'api' : 'ui';

            auditLog("{$source}.application.rollback", [
                'team_id' => $application?->team()?->id,
                'application_uuid' => $application?->uuid,
                'application_name' => $application?->name,
                'deployment_uuid' => $deployment->deployment_uuid,
                'commit' => $deployment->commit,
            ]);
        });

        static::updated(function (ApplicationDeploymentQueue $deployment): void {
            if (! auth()->check()
                || ! $deployment->wasChanged('status')
                || $deployment->status !== ApplicationDeploymentStatus::CANCELLED_BY_USER->value) {
                return;
            }

            $application = $deployment->application;
            $source = $deployment->is_api ? 'api' : 'ui';

            auditLog("{$source}.deployment.cancelled", [
                'team_id' => $application?->team()?->id,
                'application_uuid' => $application?->uuid,
                'application_name' => $application?->name,
                'deployment_uuid' => $deployment->deployment_uuid,
            ]);
        });

        static::updated(function (ApplicationDeploymentQueue $deployment): void {
            if (! $deployment->wasChanged('status')) {
                return;
            }

            try {
                ReleaseMember::wakeReleasesFor($deployment->deployment_uuid);
            } catch (\Throwable $e) {
                Log::warning("Waking releases for deployment {$deployment->deployment_uuid} failed: {$e->getMessage()}");
            }
        });
    }

    protected $fillable = [
        'application_id',
        'deployment_uuid',
        'pull_request_id',
        'docker_registry_image_tag',
        'configuration_hash',
        'configuration_snapshot',
        'configuration_diff',
        'force_rebuild',
        'commit',
        'status',
        'is_webhook',
        'logs',
        'current_process_id',
        'restart_only',
        'git_type',
        'server_id',
        'application_name',
        'server_name',
        'deployment_url',
        'destination_id',
        'only_this_server',
        'parent_deployment_uuid',
        'rollback',
        'commit_message',
        'is_api',
        'build_server_id',
        'horizon_job_id',
        'horizon_job_worker',
        'finished_at',
        'phase',
        'phase_changed_at',
        'phase_history',
        'admitted_at',
        'dispatched_at',
        'started_at',
        'artifacts',
    ];

    /**
     * The configuration snapshot/diff hold full (decrypted on read) configuration,
     * including unlocked environment variable values. They are only meant for the
     * in-app diff modal (which redacts per role) and must never be serialized by the
     * API, so hide them globally as defense in depth.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'logs',
        'configuration_snapshot',
        'configuration_diff',
    ];

    protected $casts = [
        'pull_request_id' => 'integer',
        'finished_at' => 'datetime',
        'phase' => DeploymentPhase::class,
        'phase_changed_at' => 'datetime',
        'phase_history' => 'array',
        'artifacts' => 'array',
        'admitted_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'started_at' => 'datetime',
        'configuration_snapshot' => EncryptedArrayCast::class,
        'configuration_diff' => EncryptedArrayCast::class,
    ];

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * Record an execution phase. The status column keeps its meaning (in_progress reserves a
     * deployment slot); the phase only describes where an admitted deployment really is.
     *
     * @param  array<string, mixed>  $timestamps
     * @param  list<DeploymentPhase>|null  $onlyFrom
     */
    public function recordPhase(DeploymentPhase $phase, array $timestamps = [], ?array $onlyFrom = null): bool
    {
        $now = now();
        $recorded = DB::transaction(function () use ($phase, $timestamps, $onlyFrom, $now): ?array {
            $current = static::query()->whereKey($this->getKey())->lockForUpdate()->first(['id', 'phase', 'phase_history', 'admitted_at', 'dispatched_at', 'started_at']);
            if (! $current) {
                return null;
            }

            $values = [];
            foreach ($timestamps as $column => $value) {
                if (in_array($column, ['admitted_at', 'dispatched_at', 'started_at'], true) && $current->{$column} === null) {
                    $values[$column] = $value;
                }
            }

            $allowed = ! $current->phase?->isTerminal()
                && ($onlyFrom === null || in_array($current->phase, $onlyFrom, true));
            if ($allowed && $current->phase !== $phase) {
                $history = $current->phase_history ?? [];
                $history[] = ['phase' => $phase->value, 'at' => $now->toIso8601String()];
                $values += [
                    'phase' => $phase->value,
                    'phase_changed_at' => $now,
                    'phase_history' => json_encode(array_slice($history, -30)),
                ];
            }

            if ($values === []) {
                return null;
            }

            static::query()->whereKey($this->getKey())->update($values + ['updated_at' => $now]);

            return $values;
        });

        if ($recorded === null) {
            return false;
        }

        foreach ($recorded as $column => $value) {
            $this->setAttribute($column, $column === 'phase_history' ? json_decode($value, true) : $value);
        }
        $this->syncOriginalAttributes(array_keys($recorded));

        return isset($recorded['phase']);
    }

    /**
     * @return array{label: string, type: string, description: string, phase: ?string}
     */
    public function displayState(): array
    {
        $phase = $this->phase;

        return match ($this->status) {
            ApplicationDeploymentStatus::FINISHED->value => ['label' => 'Success', 'type' => 'success', 'description' => DeploymentPhase::FINISHED->description(), 'phase' => DeploymentPhase::FINISHED->value],
            ApplicationDeploymentStatus::FAILED->value => ['label' => 'Failed', 'type' => 'error', 'description' => DeploymentPhase::FAILED->description(), 'phase' => DeploymentPhase::FAILED->value],
            ApplicationDeploymentStatus::CANCELLED_BY_USER->value => ['label' => 'Cancelled', 'type' => 'neutral', 'description' => DeploymentPhase::CANCELLED->description(), 'phase' => DeploymentPhase::CANCELLED->value],
            ApplicationDeploymentStatus::QUEUED->value => ['label' => 'Queued', 'type' => 'neutral', 'description' => DeploymentPhase::QUEUED->description(), 'phase' => DeploymentPhase::QUEUED->value],
            ApplicationDeploymentStatus::IN_PROGRESS->value => $phase !== null && ! $phase->isTerminal() && $phase !== DeploymentPhase::QUEUED
                ? ['label' => $phase->label(), 'type' => $phase->isWaiting() ? 'neutral' : 'warning', 'description' => $phase->description(), 'phase' => $phase->value]
                : ['label' => 'In progress', 'type' => 'warning', 'description' => 'The deployment is running.', 'phase' => null],
            default => ['label' => str($this->status)->headline()->toString(), 'type' => 'neutral', 'description' => '', 'phase' => $phase?->value],
        };
    }

    public function queueWaitSeconds(): ?int
    {
        $admitted = $this->admitted_at;

        return $admitted !== null && $this->created_at !== null ? max(0, (int) $this->created_at->diffInSeconds($admitted, true)) : null;
    }

    public function executionSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        $end = $this->finished_at ?? ($this->status === ApplicationDeploymentStatus::IN_PROGRESS->value ? now() : null);

        return $end !== null ? max(0, (int) $this->started_at->diffInSeconds($end, true)) : null;
    }

    public function server(): Attribute
    {
        return Attribute::make(
            get: fn () => Server::find($this->server_id),
        );
    }

    public function setStatus(string $status)
    {
        $this->update([
            'status' => $status,
        ]);
    }

    public function getOutput($name)
    {
        if (! $this->logs) {
            return null;
        }

        return collect(json_decode($this->logs))->where('name', $name)->first()?->output ?? null;
    }

    public function getHorizonJobStatus()
    {
        return getJobStatus($this->horizon_job_id);
    }

    /**
     * Horizon drops its job record 'trim.pending' minutes after the push, also while the job runs,
     * so a missing record counts as running only until the deployment timeout has passed.
     */
    public function isHorizonJobActive(): bool
    {
        return match ($this->getHorizonJobStatus()) {
            'reserved' => true,
            'unknown' => $this->updated_at?->gt(now()->subSeconds($this->server?->settings?->dynamic_timeout ?? 3600)) ?? false,
            default => false,
        };
    }

    public function commitMessage()
    {
        if (empty($this->commit_message) || is_null($this->commit_message)) {
            return null;
        }

        return str($this->commit_message)->value();
    }

    /**
     * @param  array<array-key, mixed>  $secrets
     */
    public function redactRemoteSecrets(array $secrets): void
    {
        $this->remoteSecretsForRedaction = $secrets;
    }

    private function redactSensitiveInfo($text)
    {
        try {
            $text = remove_iip($text);

            $app = $this->application;
            if (! $app) {
                return $text;
            }

            $lockedVars = collect([]);

            if ($app->environment_variables) {
                $lockedVars = $lockedVars->merge(
                    $app->environment_variables
                        ->where('is_shown_once', true)
                        ->flatMap(fn (EnvironmentVariable $variable): array => $variable->logRedactionValues())
                        ->filter()
                );
            }

            if ($this->pull_request_id !== 0 && $app->environment_variables_preview) {
                $lockedVars = $lockedVars->merge(
                    $app->environment_variables_preview
                        ->where('is_shown_once', true)
                        ->flatMap(fn (EnvironmentVariable $variable): array => $variable->logRedactionValues())
                        ->filter()
                );
            }

            $lockedVars = $lockedVars->merge(EnvironmentVariable::remoteSecretLogRedactionValues($this->remoteSecretsForRedaction));

            foreach ($lockedVars as $key => $value) {
                $escapedValue = preg_quote($value, '/');
                $text = preg_replace(
                    '/'.$escapedValue.'/',
                    REDACTED,
                    $text
                );
            }

            return is_string($text) ? $text : REDACTED;
        } catch (\Throwable) {
            return REDACTED;
        }
    }

    public function addLogEntry(string $message, string $type = 'stdout', bool $hidden = false)
    {
        if ($type === 'error') {
            $type = 'stderr';
        }
        $message = str($message)->trim();
        if ($message->startsWith('╔')) {
            $message = "\n".$message;
        }
        $newLogEntry = [
            'command' => null,
            'output' => $this->redactSensitiveInfo($message),
            'type' => $type,
            'timestamp' => Carbon::now('UTC'),
            'hidden' => $hidden,
            'batch' => 1,
        ];

        // Use a transaction to ensure atomicity
        DB::transaction(function () use ($newLogEntry) {
            // Reload the model to get the latest logs
            $this->refresh();

            if ($this->logs) {
                $previousLogs = json_decode($this->logs, associative: true, flags: JSON_THROW_ON_ERROR);
                $newLogEntry['order'] = count($previousLogs) + 1;
                $previousLogs[] = $newLogEntry;
                $this->logs = json_encode($previousLogs, flags: JSON_THROW_ON_ERROR);
            } else {
                $this->logs = json_encode([$newLogEntry], flags: JSON_THROW_ON_ERROR);
            }

            // Save without triggering events to prevent potential race conditions
            $this->saveQuietly();
        });
    }
}
