<?php

namespace App\Actions\Distribution;

use App\Enums\CoolifyUpgradeState;
use App\Models\CoolifyUpgrade;
use App\Models\Server;
use App\Services\Distribution\BuildIdentity;
use App\Services\Distribution\CoolifyDistribution;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

class ReconcileDistributionUpgrades
{
    use AsAction;

    public const RESULT_FILE = '/data/coolify/source/.upgrade-distribution-result';

    public const RESULT_STATES = [
        'aborted' => CoolifyUpgradeState::FAILED,
        'reverted' => CoolifyUpgradeState::REVERTED,
        'needs_recovery' => CoolifyUpgradeState::NEEDS_RECOVERY,
        'completed' => null,
    ];

    public int $jobTries = 1;

    public function handle(): void
    {
        $active = CoolifyUpgrade::query()->active()->orderBy('id')->get();
        if ($active->isEmpty()) {
            return;
        }

        $identity = BuildIdentity::current();
        $result = $this->readResult();

        foreach ($active as $upgrade) {
            $this->reconcile($upgrade, $identity, $result);
        }
    }

    /**
     * @param  array{upgrade_id: string, state: string, message: string}|null  $result
     */
    public function reconcile(CoolifyUpgrade $upgrade, BuildIdentity $identity, ?array $result): void
    {
        $distribution = app(CoolifyDistribution::class);
        $runsTargetBuild = $identity->isCustom()
            && $identity->buildNumber === $upgrade->to_build
            && $identity->sourceCommit === $upgrade->to_commit;

        if ($runsTargetBuild) {
            $digest = $distribution->runningDigest(fresh: true);
            if ($digest !== null) {
                $upgrade->latest_migration_after = $distribution->latestAppliedMigration();
                if (hash_equals($upgrade->to_digest, $digest)) {
                    $upgrade->finish(CoolifyUpgradeState::SUCCEEDED, 'Verified: the running build, commit and image digest match the release.');
                    $this->audit($upgrade);

                    return;
                }

                $upgrade->finish(CoolifyUpgradeState::UNVERIFIED, "The running build matches the release, but the image digest differs ({$digest}).");
                $this->audit($upgrade);

                return;
            }
        }

        if ($result !== null && $result['upgrade_id'] === $upgrade->uuid) {
            $state = self::RESULT_STATES[$result['state']] ?? null;
            if ($state !== null) {
                $upgrade->latest_migration_after = $distribution->latestAppliedMigration();
                $upgrade->finish($state, $result['message']);
                $this->audit($upgrade);

                return;
            }
        }

        if ($upgrade->hasTimedOut()) {
            $upgrade->finish(CoolifyUpgradeState::UNVERIFIED, 'The update did not report a result within '.CoolifyUpgrade::RUNNING_TIMEOUT_MINUTES.' minutes. Check the upgrade log in /data/coolify/source on the host.');
            $this->audit($upgrade);
        }
    }

    /**
     * @return array{upgrade_id: string, state: string, message: string}|null
     */
    public function readResult(): ?array
    {
        $server = Server::find(0);
        if (! $server || isDev()) {
            return null;
        }

        try {
            $content = (string) instant_remote_process(['cat '.self::RESULT_FILE.' 2>/dev/null || true'], $server, false, timeout: 30);
        } catch (Throwable) {
            return null;
        }

        return self::parseResult($content);
    }

    /**
     * @return array{upgrade_id: string, state: string, message: string}|null
     */
    public static function parseResult(string $content): ?array
    {
        $parts = explode('|', trim($content), 4);
        if (count($parts) < 3 || preg_match('/^[0-9a-f-]{36}$/', $parts[0]) !== 1 || ! array_key_exists($parts[1], self::RESULT_STATES)) {
            return null;
        }

        return [
            'upgrade_id' => $parts[0],
            'state' => $parts[1],
            'message' => mb_substr(trim($parts[2]), 0, 1000),
        ];
    }

    private function audit(CoolifyUpgrade $upgrade): void
    {
        auditLog('system.instance.distribution_upgrade_finished', [
            'team_id' => null,
            'resource' => 'instance',
            'upgrade_uuid' => $upgrade->uuid,
            'state' => $upgrade->state->value,
            'to_build' => $upgrade->to_build,
            'to_digest' => $upgrade->to_digest,
        ], $upgrade->state === CoolifyUpgradeState::SUCCEEDED ? 'info' : 'warning');
    }
}
