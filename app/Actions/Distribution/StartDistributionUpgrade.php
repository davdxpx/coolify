<?php

namespace App\Actions\Distribution;

use App\Enums\CoolifyUpgradeState;
use App\Models\CoolifyUpgrade;
use App\Models\Server;
use App\Models\User;
use App\Services\Distribution\BuildIdentity;
use App\Services\Distribution\CoolifyDistribution;
use App\Services\Distribution\DistributionRelease;
use App\Services\Distribution\DistributionUpgradePreflight;
use App\Services\Distribution\DistributionUpgradeRefused;
use App\Services\Distribution\RunningImageInspector;
use App\Services\Preflight\PreflightResult;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;

class StartDistributionUpgrade
{
    use AsAction;

    public const LOCK = 'coolify:distribution-upgrade:start';

    public const SCRIPT_SOURCE = '/usr/local/share/coolify/source/upgrade-distribution.sh';

    public const SCRIPT_TARGET = '/data/coolify/source/upgrade-distribution.sh';

    /**
     * @param  list<string>  $acknowledgedChecks
     */
    public function handle(DistributionRelease $target, string $trigger, ?User $user = null, array $acknowledgedChecks = [], ?string $reason = null): CoolifyUpgrade
    {
        $distribution = app(CoolifyDistribution::class);
        $identity = $distribution->identity();

        if (! $identity->isCustom() || ! $identity->isValid()) {
            throw new DistributionUpgradeRefused('This installation does not run a valid custom distribution. Distribution updates are not available.');
        }

        if ($target->image !== $identity->imageRepository) {
            throw new DistributionUpgradeRefused('The release image is outside the trusted repository of the running build.');
        }

        $lock = Cache::lock(self::LOCK, 300);
        if (! $lock->get()) {
            throw new DistributionUpgradeRefused('Another update request is being processed. Try again in a few minutes.');
        }

        try {
            $rollback = $trigger === CoolifyUpgrade::TRIGGER_ROLLBACK;
            $results = app(DistributionUpgradePreflight::class)->run($target, $rollback);
            $acknowledgedChecks = array_values(array_intersect(
                $acknowledgedChecks,
                array_map(fn (PreflightResult $result) => $result->id, array_filter($results, fn (PreflightResult $result) => $result->overridable && $result->blocks())),
            ));
            $blocking = PreflightResult::blocking($results, $acknowledgedChecks);

            if ($acknowledgedChecks !== [] && blank($reason)) {
                throw new DistributionUpgradeRefused('Acknowledging a failed check requires a reason.');
            }

            $includesMigrations = null;
            foreach ($results as $result) {
                if ($result->id === 'schema_compatibility') {
                    $includesMigrations = $result->details['includes_migrations'] ?? null;
                }
            }

            $running = $distribution->state()->running;
            $upgrade = CoolifyUpgrade::create([
                'distribution' => $identity->distribution,
                'channel' => $rollback ? null : $distribution->channel(),
                'trigger' => $trigger,
                'state' => $blocking === [] ? CoolifyUpgradeState::RUNNING : CoolifyUpgradeState::PREFLIGHT_FAILED,
                'from_version' => $running?->version ?? "build {$identity->buildNumber}",
                'from_base_version' => $identity->baseVersion,
                'from_build' => $identity->buildNumber,
                'from_commit' => $identity->sourceCommit,
                'from_digest' => $distribution->runningDigest(fresh: true),
                'from_latest_migration' => $distribution->latestAppliedMigration(),
                'to_version' => $target->version,
                'to_base_version' => $target->baseVersion,
                'to_build' => $target->build,
                'to_commit' => $target->commit,
                'to_image' => $target->image,
                'to_digest' => $target->digest,
                'to_latest_migration' => $target->latestMigration,
                'includes_migrations' => $includesMigrations,
                'preflight' => array_map(fn (PreflightResult $result) => $result->toArray(), $results),
                'acknowledged_checks' => $acknowledgedChecks,
                'acknowledgement_reason' => $acknowledgedChecks !== [] ? mb_substr((string) $reason, 0, 1000) : null,
                'requested_by_user_id' => $user?->id,
                'started_at' => $blocking === [] ? now() : null,
                'finished_at' => $blocking === [] ? null : now(),
                'message' => $blocking === [] ? null : implode(' ', array_map(fn (PreflightResult $result) => $result->summary, $blocking)),
            ]);

            $source = $trigger === CoolifyUpgrade::TRIGGER_AUTOMATIC ? 'scheduler' : 'ui';
            auditLog($blocking === [] ? "{$source}.instance.distribution_upgrade_started" : "{$source}.instance.distribution_upgrade_blocked", [
                'team_id' => null,
                'resource' => 'instance',
                'upgrade_uuid' => $upgrade->uuid,
                'trigger' => $trigger,
                'from_build' => $upgrade->from_build,
                'to_build' => $upgrade->to_build,
                'to_version' => $upgrade->to_version,
                'to_digest' => $upgrade->to_digest,
                'acknowledged_checks' => $acknowledgedChecks,
                'blocking_checks' => array_map(fn (PreflightResult $result) => $result->id, $blocking),
            ]);

            if ($blocking !== []) {
                throw new DistributionUpgradeRefused('The update is blocked by failed checks.', $upgrade, $blocking);
            }

            app(RunningImageInspector::class)->forget();
            $this->launch($upgrade, $identity);

            return $upgrade;
        } finally {
            $lock->release();
        }
    }

    protected function launch(CoolifyUpgrade $upgrade, BuildIdentity $identity): void
    {
        $script = @file_get_contents(self::SCRIPT_SOURCE);
        if ($script === false || $script === '') {
            $upgrade->finish(CoolifyUpgradeState::FAILED, 'The distribution upgrade script is missing from this image.');

            throw new DistributionUpgradeRefused('The distribution upgrade script is missing from this image.');
        }

        remote_process(self::commands($upgrade, $identity, $script, getHelperVersion(), coolifyRegistryUrl()), Server::findOrFail(0));
    }

    /**
     * @return list<string>
     */
    public static function commands(CoolifyUpgrade $upgrade, BuildIdentity $identity, string $script, string $helperVersion, string $registryUrl): array
    {
        $arguments = [
            '--upgrade-id', $upgrade->uuid,
            '--image', $upgrade->to_image,
            '--digest', $upgrade->to_digest,
            '--commit', $upgrade->to_commit,
            '--build', (string) $upgrade->to_build,
            '--base-version', $upgrade->to_base_version,
            '--version', $upgrade->to_version,
            '--distribution', (string) $identity->distribution,
            '--helper-version', $helperVersion,
            '--registry', $registryUrl,
        ];

        if ($upgrade->to_latest_migration !== null) {
            array_push($arguments, '--expected-migration', $upgrade->to_latest_migration);
        }

        if ($identity->requiresSignature()) {
            array_push($arguments, '--signature-issuer', (string) $identity->signatureIssuer, '--signature-identity-regexp', (string) $identity->signatureIdentityPattern);
        }

        if ($upgrade->trigger === CoolifyUpgrade::TRIGGER_ROLLBACK) {
            $arguments[] = '--rollback';
        }

        return [
            "echo '".base64_encode($script)."' | base64 -d | tee ".self::SCRIPT_TARGET.' > /dev/null',
            'bash '.self::SCRIPT_TARGET.' '.implode(' ', array_map('escapeshellarg', $arguments)),
        ];
    }
}
