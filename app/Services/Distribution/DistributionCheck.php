<?php

namespace App\Services\Distribution;

final class DistributionCheck
{
    public function __construct(
        public readonly ?DistributionManifest $manifest,
        public readonly ?string $checkedAt,
        public readonly ?string $error,
        public readonly bool $stale = false,
    ) {}

    public function succeeded(): bool
    {
        return $this->error === null && $this->manifest !== null;
    }
}
