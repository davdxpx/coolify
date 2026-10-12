<?php

namespace App\Services\Distribution;

use App\Models\CoolifyUpgrade;
use App\Services\Preflight\PreflightResult;
use RuntimeException;

final class DistributionUpgradeRefused extends RuntimeException
{
    /**
     * @param  list<PreflightResult>  $blocking
     */
    public function __construct(string $message, public readonly ?CoolifyUpgrade $upgrade = null, public readonly array $blocking = [])
    {
        parent::__construct($message);
    }
}
