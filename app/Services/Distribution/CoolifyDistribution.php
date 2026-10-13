<?php

namespace App\Services\Distribution;

use App\Enums\CoolifyUpgradeState;
use App\Models\CoolifyUpgrade;
use App\Models\InstanceSettings;
use Illuminate\Support\Facades\DB;
use Throwable;

class CoolifyDistribution
{
    public function __construct(
        private readonly DistributionManifestClient $client,
        private readonly RunningImageInspector $inspector,
    ) {}

    public function identity(): BuildIdentity
    {
        return BuildIdentity::current();
    }

    public function isCustom(): bool
    {
        return $this->identity()->isCustom();
    }

    public function channel(): string
    {
        $channel = InstanceSettings::query()->whereKey(0)->value('distribution_channel');

        return in_array($channel, DistributionManifest::CHANNELS, true) ? $channel : 'stable';
    }

    public function state(): DistributionUpdateState
    {
        $identity = $this->identity();

        return DistributionUpdateState::resolve($identity, $identity->isCustom() ? $this->client->lastCheck($identity) : null, $this->channel());
    }

    public function refresh(): DistributionUpdateState
    {
        $identity = $this->identity();
        if (! $identity->isCustom()) {
            return $this->state();
        }

        $check = $identity->isValid()
            ? $this->client->check($identity)
            : new DistributionCheck(null, now()->toIso8601String(), $identity->invalidReason);

        $state = DistributionUpdateState::resolve($identity, $check, $this->channel());
        InstanceSettings::query()->whereKey(0)->update(['new_version_available' => $state->updateAvailable]);

        return $state;
    }

    public function runningImage(bool $fresh = false): ?RunningImage
    {
        return $this->inspector->inspect($fresh);
    }

    public function runningDigest(bool $fresh = false): ?string
    {
        $repository = $this->identity()->imageRepository;

        return $repository !== null ? $this->runningImage($fresh)?->digestFor($repository) : null;
    }

    public function latestAppliedMigration(): ?string
    {
        try {
            $latest = DB::table('migrations')->max('migration');
        } catch (Throwable) {
            return null;
        }

        return is_string($latest) ? $latest : null;
    }

    public function officialLatestVersion(): ?string
    {
        $version = get_latest_version_of_coolify();

        return OfficialVersion::isValid($version) && $version !== '0.0.0' ? $version : null;
    }

    public function activeUpgrade(): ?CoolifyUpgrade
    {
        return CoolifyUpgrade::query()->active()->latest('id')->first();
    }

    public function rollbackCandidate(): ?RollbackCandidate
    {
        $identity = $this->identity();
        if (! $identity->isCustom() || ! $identity->isValid()) {
            return null;
        }

        $upgrade = CoolifyUpgrade::query()
            ->where('state', CoolifyUpgradeState::SUCCEEDED->value)
            ->where('to_build', $identity->buildNumber)
            ->where('to_commit', $identity->sourceCommit)
            ->latest('finished_at')
            ->first();

        return $upgrade !== null
            ? RollbackCandidate::fromUpgrade($upgrade, $identity, $this->latestAppliedMigration())
            : null;
    }
}
