<?php

namespace App\Services\Distribution;

use App\Models\CoolifyUpgrade;

final class RollbackCandidate
{
    public function __construct(
        public readonly CoolifyUpgrade $sourceUpgrade,
        public readonly ?DistributionRelease $release,
        public readonly bool $imageRollbackSafe,
        public readonly string $explanation,
    ) {}

    public static function fromUpgrade(CoolifyUpgrade $upgrade, BuildIdentity $identity, ?string $currentLatestMigration): self
    {
        if ($upgrade->from_build === null || $upgrade->from_commit === null || $upgrade->from_digest === null || $upgrade->from_base_version === null) {
            return new self($upgrade, null, false, 'The previous version was not installed from this distribution channel, so it cannot be restored from here. Follow the documented recovery procedure.');
        }

        $release = new DistributionRelease(
            version: $upgrade->from_version ?? "build {$upgrade->from_build}",
            baseVersion: $upgrade->from_base_version,
            build: $upgrade->from_build,
            commit: $upgrade->from_commit,
            image: (string) $identity->imageRepository,
            digest: $upgrade->from_digest,
            channels: [],
            verificationStatus: DistributionRelease::VERIFICATION_PASSED,
            latestMigration: $upgrade->from_latest_migration,
        );

        if ($upgrade->from_latest_migration === null || $currentLatestMigration === null) {
            return new self($upgrade, $release, false, 'The database schema state before the update is unknown. Restoring the previous image could run old code against a newer schema. Restore the database backup together with the image instead.');
        }

        if (strcmp($currentLatestMigration, $upgrade->from_latest_migration) > 0) {
            return new self($upgrade, $release, false, "The update applied database migrations (latest: {$currentLatestMigration}). The previous image does not know them. Restore the database backup taken before the update together with the previous image, or install a forward fix.");
        }

        return new self($upgrade, $release, true, 'No database migrations were applied since the previous version was running, so its image can be restored directly.');
    }
}
