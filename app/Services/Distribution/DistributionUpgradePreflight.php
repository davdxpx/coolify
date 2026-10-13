<?php

namespace App\Services\Distribution;

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\PreflightState;
use App\Models\ApplicationDeploymentQueue;
use App\Models\CoolifyUpgrade;
use App\Models\InstanceSettings;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Services\CoolifyUpgradeStatus;
use App\Services\Preflight\PreflightResult;
use App\Services\Registry\ManifestLookup;
use App\Services\Registry\OciRegistryClient;
use Illuminate\Support\Carbon;
use Throwable;

class DistributionUpgradePreflight
{
    public const MIN_FREE_DISK_KB = 3145728;

    public function __construct(
        private readonly CoolifyDistribution $distribution,
        private readonly OciRegistryClient $registry,
    ) {}

    /**
     * @return list<PreflightResult>
     */
    public function run(DistributionRelease $target, bool $rollback = false): array
    {
        $identity = $this->distribution->identity();
        $schema = $this->schemaCheck($target, $this->distribution->latestAppliedMigration(), $rollback);
        $server = Server::find(0);

        return [
            $this->identityCheck($identity),
            $this->serverCheck($server),
            $this->verificationCheck($target),
            $rollback ? $this->rollbackOrderCheck($target, $identity) : $this->orderCheck($target, $identity),
            $schema,
            $this->backupCheck($schema->details['includes_migrations'] ?? null),
            $this->deploymentsCheck(),
            $this->upgradeLockCheck($server),
            $this->diskCheck($server),
            $this->registryCheck($target),
            $this->signatureCheck($identity),
        ];
    }

    public function identityCheck(BuildIdentity $identity): PreflightResult
    {
        if (! $identity->isCustom()) {
            return $this->result('build_identity', 'Running build', PreflightState::FAILED, 'This installation runs the official distribution. Switching to a custom distribution is a separate, manual migration.');
        }

        if (! $identity->isValid()) {
            return $this->result('build_identity', 'Running build', PreflightState::FAILED, (string) $identity->invalidReason);
        }

        return $this->result('build_identity', 'Running build', PreflightState::PASSED, "Build {$identity->buildNumber} of {$identity->distribution} ({$identity->shortCommit()}).");
    }

    public function serverCheck(?Server $server): PreflightResult
    {
        if (! $server) {
            return $this->result('host_server', 'Coolify host', PreflightState::FAILED, 'The server that runs Coolify (localhost) is missing.');
        }

        return $server->isFunctional()
            ? $this->result('host_server', 'Coolify host', PreflightState::PASSED, 'The Coolify host is marked reachable and usable.')
            : $this->result('host_server', 'Coolify host', PreflightState::FAILED, 'The Coolify host is marked unreachable or unusable. Validate the localhost server first.');
    }

    public function verificationCheck(DistributionRelease $target): PreflightResult
    {
        return $target->isVerified()
            ? $this->result('ci_verification', 'CI verification', PreflightState::PASSED, "Release {$target->version} passed CI verification.", details: ['url' => $target->verificationUrl])
            : $this->result('ci_verification', 'CI verification', PreflightState::FAILED, "Release {$target->version} has not passed CI verification ({$target->verificationStatus}).", remediation: 'Fix the failing workflow and publish a new build.');
    }

    public function orderCheck(DistributionRelease $target, BuildIdentity $identity): PreflightResult
    {
        if ($identity->buildNumber === null || ! $target->isNewerThanBuild($identity)) {
            return $this->result('release_order', 'Release order', PreflightState::FAILED, "Release {$target->version} (build {$target->build}) is not newer than the running build {$identity->buildNumber}. Use the rollback action to return to an earlier build.");
        }

        if ($target->hasOlderBaseThan($identity)) {
            return $this->result('release_order', 'Release order', PreflightState::FAILED, "Release {$target->version} is based on Coolify {$target->baseVersion}, which is older than the running base {$identity->baseVersion}.");
        }

        return $this->result('release_order', 'Release order', PreflightState::PASSED, "Build {$identity->buildNumber} → {$target->build}, base {$identity->baseVersion} → {$target->baseVersion}.");
    }

    public function rollbackOrderCheck(DistributionRelease $target, BuildIdentity $identity): PreflightResult
    {
        if ($identity->buildNumber === null || $target->build === $identity->buildNumber) {
            return $this->result('release_order', 'Release order', PreflightState::FAILED, 'The rollback target is the running build.');
        }

        return $this->result('release_order', 'Release order', PreflightState::PASSED, "Rollback from build {$identity->buildNumber} to build {$target->build}.");
    }

    public function schemaCheck(DistributionRelease $target, ?string $databaseLatest, bool $rollback): PreflightResult
    {
        $label = 'Database schema';
        if ($databaseLatest === null) {
            return $this->result('schema_compatibility', $label, PreflightState::UNKNOWN, 'The applied database migrations cannot be read.', details: ['includes_migrations' => null]);
        }

        if ($target->latestMigration === null) {
            return $rollback
                ? $this->result('schema_compatibility', $label, PreflightState::FAILED, 'The rollback target does not declare its migrations, so schema compatibility cannot be proven.', details: ['includes_migrations' => null])
                : $this->result('schema_compatibility', $label, PreflightState::WARNING, 'The release does not declare its migrations. Treat it as an update with database migrations.', required: false, details: ['includes_migrations' => null]);
        }

        $comparison = strcmp($target->latestMigration, $databaseLatest);
        if ($comparison < 0) {
            return $this->result('schema_compatibility', $label, PreflightState::FAILED, "The database already contains migrations that release {$target->version} does not know (database: {$databaseLatest}, release: {$target->latestMigration}). Running it could corrupt data.", remediation: 'Restore a database backup that matches this release, or install a newer build.', details: ['includes_migrations' => false]);
        }

        if ($comparison > 0) {
            return $this->result('schema_compatibility', $label, PreflightState::PASSED, 'The update runs database migrations. After it, the previous image cannot be restored without restoring the database backup too.', details: ['includes_migrations' => true]);
        }

        return $this->result('schema_compatibility', $label, PreflightState::PASSED, 'The release uses the current database schema. No migrations run.', details: ['includes_migrations' => false]);
    }

    public function backupCheck(?bool $includesMigrations): PreflightResult
    {
        $maxAgeHours = max(1, (int) (InstanceSettings::query()->whereKey(0)->value('distribution_backup_max_age_hours') ?? 24));
        $required = $includesMigrations !== false;
        $latest = $this->latestInstanceBackup();

        if ($latest !== null && $latest->greaterThanOrEqualTo(now()->subHours($maxAgeHours))) {
            return $this->result('instance_backup', 'Coolify database backup', PreflightState::PASSED, "The newest successful backup of the Coolify database is from {$latest->toIso8601String()}.", required: $required, details: ['latest_success_at' => $latest->toIso8601String()]);
        }

        $summary = $latest === null
            ? 'No successful backup of the Coolify database was found.'
            : "The newest successful backup of the Coolify database is older than {$maxAgeHours} hours ({$latest->toIso8601String()}).";

        return $this->result(
            'instance_backup',
            'Coolify database backup',
            $required ? PreflightState::FAILED : PreflightState::WARNING,
            $summary.($required ? ' This update may run migrations.' : ''),
            required: $required,
            overridable: true,
            remediation: 'Run a backup in Settings → Backup and confirm it can be restored, or acknowledge that a verified backup exists outside Coolify.',
            details: ['latest_success_at' => $latest?->toIso8601String(), 'max_age_hours' => $maxAgeHours],
        );
    }

    public function deploymentsCheck(): PreflightResult
    {
        $running = ApplicationDeploymentQueue::query()->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)->count();

        return $running === 0
            ? $this->result('active_deployments', 'Running deployments', PreflightState::PASSED, 'No deployment is running.')
            : $this->result('active_deployments', 'Running deployments', PreflightState::FAILED, "{$running} deployment(s) are running. They fail when Coolify restarts.", overridable: true, remediation: 'Wait for the deployments to finish, or acknowledge that they will fail.', details: ['running' => $running]);
    }

    public function upgradeLockCheck(?Server $server): PreflightResult
    {
        $active = CoolifyUpgrade::query()->active()->latest('id')->first();
        if ($active !== null && ! $active->hasTimedOut()) {
            return $this->result('upgrade_lock', 'Other updates', PreflightState::FAILED, "Update {$active->uuid} to {$active->to_version} is still running.");
        }

        if ($server !== null) {
            try {
                $status = (string) instant_remote_process(['cat '.CoolifyUpgradeStatus::FILE.' 2>/dev/null || true'], $server, false);
            } catch (Throwable) {
                return $this->result('upgrade_lock', 'Other updates', PreflightState::UNKNOWN, 'The upgrade status file on the host could not be read.');
            }
            if (CoolifyUpgradeStatus::isRunning($status)) {
                return $this->result('upgrade_lock', 'Other updates', PreflightState::FAILED, 'Another upgrade script is running on the host.');
            }
        }

        return $this->result('upgrade_lock', 'Other updates', PreflightState::PASSED, 'No other update is running.');
    }

    public function diskCheck(?Server $server): PreflightResult
    {
        if ($server === null) {
            return $this->result('host_disk', 'Free disk space', PreflightState::UNKNOWN, 'The host is unknown.', required: false);
        }

        try {
            $output = (string) instant_remote_process([self::diskCommand()], $server, false, timeout: 30);
        } catch (Throwable) {
            $output = '';
        }

        $availableKb = self::parseAvailableKb($output);
        if ($availableKb === null) {
            return $this->result('host_disk', 'Free disk space', PreflightState::WARNING, 'The free space of the Docker data directory could not be measured.', required: false);
        }

        $availableGb = round($availableKb / 1048576, 1);

        return $availableKb >= self::MIN_FREE_DISK_KB
            ? $this->result('host_disk', 'Free disk space', PreflightState::PASSED, "{$availableGb} GB free for Docker images.", details: ['available_kb' => $availableKb])
            : $this->result('host_disk', 'Free disk space', PreflightState::FAILED, "Only {$availableGb} GB are free for Docker images; at least 3 GB are needed.", remediation: 'Free disk space on the Coolify host first.', details: ['available_kb' => $availableKb]);
    }

    public function registryCheck(DistributionRelease $target): PreflightResult
    {
        $lookup = $this->registry->manifestStatus($target->image, $target->digest);

        return match ($lookup) {
            ManifestLookup::FOUND => $this->result('image_available', 'Release image', PreflightState::PASSED, "{$target->image}@{$target->shortDigest()} exists in the registry."),
            ManifestLookup::NOT_FOUND => $this->result('image_available', 'Release image', PreflightState::FAILED, "The registry does not have {$target->image}@{$target->digest}."),
            ManifestLookup::INVALID_REFERENCE => $this->result('image_available', 'Release image', PreflightState::FAILED, 'The release image reference is invalid.'),
            ManifestLookup::AUTH_REQUIRED => $this->result('image_available', 'Release image', PreflightState::WARNING, 'The registry requires credentials. The host uses its own Docker login when it pulls the image; the update stops if that pull fails.', required: false),
            ManifestLookup::UNREACHABLE => $this->result('image_available', 'Release image', PreflightState::WARNING, 'The registry could not be reached from Coolify. The update stops if the host cannot pull the image.', required: false),
        };
    }

    public function signatureCheck(BuildIdentity $identity): PreflightResult
    {
        return $identity->requiresSignature()
            ? $this->result('image_signature', 'Image signature', PreflightState::PASSED, 'The host verifies the image signature before switching. The update stops if verification fails.', required: false)
            : $this->result('image_signature', 'Image signature', PreflightState::WARNING, 'This build does not require signed images. Only the digest pins the release.', required: false);
    }

    public static function diskCommand(): string
    {
        return "sh -c 'df -Pk \"\$(docker info --format \"{{.DockerRootDir}}\" 2>/dev/null || echo /)\"'";
    }

    public static function parseAvailableKb(string $output): ?int
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", trim($output)))));
        $last = end($lines);
        if (! is_string($last)) {
            return null;
        }

        $columns = preg_split('/\s+/', $last);

        return isset($columns[3]) && ctype_digit($columns[3]) ? (int) $columns[3] : null;
    }

    private function latestInstanceBackup(): ?Carbon
    {
        $backupIds = ScheduledDatabaseBackup::query()
            ->where('database_type', StandalonePostgresql::class)
            ->where('database_id', 0)
            ->pluck('id');

        if ($backupIds->isEmpty()) {
            return null;
        }

        $execution = ScheduledDatabaseBackupExecution::query()
            ->whereIn('scheduled_database_backup_id', $backupIds)
            ->where('status', 'success')
            ->latest('id')
            ->first();

        if ($execution === null) {
            return null;
        }

        return $execution->finished_at ?? $execution->created_at;
    }

    /**
     * @param  array<string, scalar|null>  $details
     */
    private function result(string $id, string $label, PreflightState $state, string $summary, bool $required = true, bool $overridable = false, ?string $remediation = null, array $details = []): PreflightResult
    {
        return new PreflightResult($id, $label, $state, $summary, $required, $overridable, $remediation, $details, 'distribution');
    }
}
