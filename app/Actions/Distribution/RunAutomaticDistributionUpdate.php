<?php

namespace App\Actions\Distribution;

use App\Models\CoolifyUpgrade;
use App\Models\InstanceSettings;
use App\Services\Distribution\CoolifyDistribution;
use App\Services\Distribution\DistributionUpgradeRefused;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

class RunAutomaticDistributionUpdate
{
    use AsAction;

    public function handle(): ?CoolifyUpgrade
    {
        $distribution = app(CoolifyDistribution::class);
        if (! $distribution->isCustom()) {
            return null;
        }

        $enabled = (bool) InstanceSettings::query()->whereKey(0)->value('is_distribution_auto_update_enabled');
        if (! $enabled) {
            return null;
        }

        $state = $distribution->refresh();
        if (! $state->updateAvailable || $state->candidate === null) {
            return null;
        }

        try {
            return StartDistributionUpgrade::run($state->candidate, CoolifyUpgrade::TRIGGER_AUTOMATIC);
        } catch (DistributionUpgradeRefused $e) {
            Log::warning('Automatic distribution update skipped', [
                'target_build' => $state->candidate->build,
                'reason' => $e->getMessage(),
                'blocking_checks' => array_map(fn ($result) => $result->id, $e->blocking),
            ]);

            return $e->upgrade;
        }
    }
}
