<?php

namespace App\Services\Backup;

use App\Models\BackupRestoreEvidence;
use App\Models\Environment;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * When each database last had a successful backup. Only facts from Coolify's own backup
 * executions are used; a database without an enabled schedule is reported as not configured.
 */
class BackupFreshness
{
    /**
     * @return list<array{database_uuid: string, database_name: string, database_type: string, configured: bool, last_success_at: Carbon|null, last_status: string|null, last_attempt_at: Carbon|null, age_hours: float|null}>
     */
    public function forEnvironment(Environment $environment): array
    {
        $databases = $environment->databases();
        foreach ($environment->services()->with('databases')->get() as $service) {
            $databases = $databases->concat($service->databases);
        }

        return $databases->map(fn (Model $database) => $this->forDatabase($database))->values()->all();
    }

    /**
     * @return array{database_uuid: string, database_name: string, database_type: string, configured: bool, last_success_at: Carbon|null, last_status: string|null, last_attempt_at: Carbon|null, age_hours: float|null, last_restore_verified_at: string|null, last_restore_result: string|null}
     */
    public function forDatabase(Model $database): array
    {
        $backupIds = ScheduledDatabaseBackup::query()
            ->where('database_type', $database->getMorphClass())
            ->where('database_id', $database->getKey())
            ->where('enabled', true)
            ->pluck('id');

        $lastSuccess = $backupIds->isEmpty() ? null : ScheduledDatabaseBackupExecution::query()
            ->whereIn('scheduled_database_backup_id', $backupIds)
            ->where('status', 'success')
            ->latest('id')
            ->first(['finished_at', 'updated_at', 'created_at']);
        $lastAttempt = $backupIds->isEmpty() ? null : ScheduledDatabaseBackupExecution::query()
            ->whereIn('scheduled_database_backup_id', $backupIds)
            ->latest('id')
            ->first(['status', 'created_at']);
        $successAt = $lastSuccess ? ($lastSuccess->finished_at ?? $lastSuccess->updated_at ?? $lastSuccess->created_at) : null;
        $restore = BackupRestoreEvidence::query()
            ->where('database_type', $database->getMorphClass())
            ->where('database_id', $database->getKey())
            ->latest('verified_at')
            ->first(['result', 'verified_at']);

        return [
            'database_uuid' => (string) $database->uuid,
            'database_name' => (string) $database->name,
            'database_type' => class_basename($database),
            'configured' => $backupIds->isNotEmpty(),
            'last_success_at' => $successAt,
            'last_status' => $lastAttempt?->status,
            'last_attempt_at' => $lastAttempt?->created_at,
            'age_hours' => $successAt ? round($successAt->diffInMinutes(now(), true) / 60, 1) : null,
            'last_restore_verified_at' => $restore?->verified_at?->toIso8601String(),
            'last_restore_result' => $restore?->result,
        ];
    }
}
