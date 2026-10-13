<?php

namespace App\Services\Distribution;

final class DistributionUpdateState
{
    public function __construct(
        public readonly BuildIdentity $identity,
        public readonly string $channel,
        public readonly ?DistributionCheck $check,
        public readonly ?DistributionRelease $running,
        public readonly ?DistributionRelease $candidate,
        public readonly bool $updateAvailable,
        public readonly ?string $blockedReason,
        public readonly bool $manifestDisagreesWithBuild,
    ) {}

    public static function resolve(BuildIdentity $identity, ?DistributionCheck $check, string $channel): self
    {
        $channel = in_array($channel, DistributionManifest::CHANNELS, true) ? $channel : 'stable';
        $manifest = $check?->manifest;

        if (! $identity->isCustom()) {
            return new self($identity, $channel, $check, null, null, false, null, false);
        }

        if (! $identity->isValid()) {
            return new self($identity, $channel, $check, null, null, false, $identity->invalidReason, false);
        }

        $running = $manifest?->findByBuild($identity->buildNumber);
        $disagrees = $running !== null && $running->commit !== $identity->sourceCommit;
        $candidate = $manifest?->releaseForChannel($channel);

        $blockedReason = null;
        $available = false;
        if ($disagrees) {
            $blockedReason = 'The distribution manifest lists a different commit for the running build. Updates stay blocked until this is resolved.';
        } elseif ($candidate !== null && $candidate->isNewerThanBuild($identity)) {
            if ($candidate->hasOlderBaseThan($identity)) {
                $blockedReason = "Release {$candidate->version} is based on Coolify {$candidate->baseVersion}, which is older than the running base {$identity->baseVersion}.";
            } elseif (! $candidate->isVerified()) {
                $blockedReason = "Release {$candidate->version} has not passed CI verification ({$candidate->verificationStatus}).";
            } else {
                $available = true;
            }
        }

        return new self($identity, $channel, $check, $running, $candidate, $available, $blockedReason, $disagrees);
    }

    public function runningLabel(): string
    {
        if (! $this->identity->isCustom()) {
            return (string) config('constants.coolify.version');
        }

        if ($this->running !== null) {
            return $this->running->version;
        }

        return $this->identity->buildNumber !== null
            ? "build {$this->identity->buildNumber}"
            : 'unknown build';
    }
}
