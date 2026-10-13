<?php

namespace App\Livewire\Settings;

use App\Actions\Distribution\StartDistributionUpgrade;
use App\Jobs\CheckForUpdatesJob;
use App\Models\CoolifyUpgrade;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Services\Distribution\CoolifyDistribution;
use App\Services\Distribution\DistributionManifest;
use App\Services\Distribution\DistributionUpgradeRefused;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Updates extends Component
{
    use AuthorizesRequests;

    public InstanceSettings $settings;

    public ?Server $server = null;

    #[Validate('string')]
    public string $auto_update_frequency;

    #[Validate('string|required')]
    public string $update_check_frequency;

    #[Validate('boolean')]
    public bool $is_auto_update_enabled;

    #[Validate('required|string|in:docker.io,ghcr.io')]
    public string $docker_registry_url;

    #[Locked]
    public bool $isCustomDistribution = false;

    public string $distribution_channel = 'stable';

    public bool $is_distribution_auto_update_enabled = false;

    public int $distribution_backup_max_age_hours = 24;

    public string $rollbackReason = '';

    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }
        if (! isCloud()) {
            $this->server = Server::findOrFail(0);
        }

        $this->settings = instanceSettings();
        $this->auto_update_frequency = $this->settings->auto_update_frequency;
        $this->update_check_frequency = $this->settings->update_check_frequency;
        $this->is_auto_update_enabled = $this->settings->is_auto_update_enabled;
        $this->docker_registry_url = $this->settings->docker_registry_url ?: 'docker.io';

        $this->isCustomDistribution = app(CoolifyDistribution::class)->isCustom();
        $this->distribution_channel = $this->settings->distribution_channel ?: 'stable';
        $this->is_distribution_auto_update_enabled = (bool) $this->settings->is_distribution_auto_update_enabled;
        $this->distribution_backup_max_age_hours = (int) ($this->settings->distribution_backup_max_age_hours ?: 24);
    }

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->settings);
            if ($this->autoUpdatesEnabled()) {
                $this->validate([
                    'auto_update_frequency' => ['required', 'string'],
                ]);
            }
            $validated = $this->validate([
                'docker_registry_url' => ['required', 'string', 'in:docker.io,ghcr.io'],
                'distribution_channel' => ['required', 'string', Rule::in(DistributionManifest::CHANNELS)],
                'is_distribution_auto_update_enabled' => ['boolean'],
                'distribution_backup_max_age_hours' => ['required', 'integer', 'min:1', 'max:720'],
            ]);
            $this->settings->auto_update_frequency = $this->auto_update_frequency;
            $this->settings->update_check_frequency = $this->update_check_frequency;
            $this->settings->is_auto_update_enabled = $this->is_auto_update_enabled;
            $this->settings->docker_registry_url = $validated['docker_registry_url'];
            $this->settings->distribution_channel = $validated['distribution_channel'];
            $this->settings->is_distribution_auto_update_enabled = $validated['is_distribution_auto_update_enabled'];
            $this->settings->distribution_backup_max_age_hours = $validated['distribution_backup_max_age_hours'];
            $this->syncRegistryUrlToEnv($validated['docker_registry_url']);
            $changedFields = auditChangedFields($this->settings);
            $channelChanged = $this->settings->isDirty('distribution_channel');
            $this->settings->save();
            if ($changedFields !== []) {
                auditLog('ui.instance.settings.updated', [
                    'team_id' => null,
                    'resource' => 'instance',
                    'section' => 'updates',
                    'changed_fields' => $changedFields,
                ]);
            }
            if ($channelChanged && $this->isCustomDistribution) {
                app(CoolifyDistribution::class)->refresh();
                $this->dispatch('updateAvailable');
            }
            $this->dispatch('success', 'Settings updated!');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    protected function syncRegistryUrlToEnv(string $registryUrl): void
    {
        if (! $this->server) {
            return;
        }

        try {
            instant_remote_process($this->registryEnvSyncCommand($registryUrl), $this->server);
        } catch (\Exception $e) {
            Log::warning('Failed to sync REGISTRY_URL to .env', [
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Failed to sync REGISTRY_URL to .env. Settings were not saved.', previous: $e);
        }
    }

    /**
     * One command per line: the non-root sudo parser adds sudo to line starts, not to `then`/`else` branches,
     * and a `>>` redirect would be opened by the SSH user.
     *
     * @return list<string>
     */
    private function registryEnvSyncCommand(string $registryUrl): array
    {
        $envFile = '/data/coolify/source/.env';
        $sedExpression = escapeshellarg("s|^REGISTRY_URL=.*|REGISTRY_URL={$registryUrl}|");
        $registryLine = escapeshellarg("REGISTRY_URL={$registryUrl}");

        return [
            "if grep -q '^REGISTRY_URL=' {$envFile}; then",
            "    sed -i {$sedExpression} {$envFile}",
            'else',
            "    printf '%s\\n' {$registryLine} | tee -a {$envFile} > /dev/null",
            'fi',
        ];
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->settings);
            $this->resetErrorBag();
            $this->validate();

            if ($this->autoUpdatesEnabled() && ! validate_cron_expression($this->auto_update_frequency)) {
                $this->dispatch('error', 'Invalid Cron / Human expression for Auto Update Frequency.');
                if (empty($this->auto_update_frequency)) {
                    $this->auto_update_frequency = '0 0 * * *';
                }

                return;
            }

            if (! validate_cron_expression($this->update_check_frequency)) {
                $this->dispatch('error', 'Invalid Cron / Human expression for Update Check Frequency.');
                if (empty($this->update_check_frequency)) {
                    $this->update_check_frequency = '0 * * * *';
                }

                return;
            }

            $this->instantSave();
            if ($this->server) {
                $this->server->setupDynamicProxyConfiguration();
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function checkManually()
    {
        $this->authorize('update', $this->settings);
        CheckForUpdatesJob::dispatchSync();
        $this->dispatch('updateAvailable');
        $settings = instanceSettings();
        if ($settings->new_version_available) {
            $this->dispatch('success', 'New version available!');
        } else {
            $this->dispatch('success', 'No new version available.');
        }
    }

    public function rollback()
    {
        try {
            $this->authorize('update', $this->settings);
            if (! $this->isCustomDistribution) {
                abort(403);
            }

            $candidate = app(CoolifyDistribution::class)->rollbackCandidate();
            if ($candidate === null || $candidate->release === null || ! $candidate->imageRollbackSafe) {
                $this->dispatch('error', $candidate?->explanation ?? 'No previous build is available for a rollback.');

                return;
            }

            StartDistributionUpgrade::run($candidate->release, CoolifyUpgrade::TRIGGER_ROLLBACK, auth()->user());
            $this->dispatch('success', "Rollback to {$candidate->release->version} started. Coolify restarts in a few minutes.");
        } catch (DistributionUpgradeRefused $e) {
            $this->dispatch('error', $e->getMessage());
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function autoUpdatesEnabled(): bool
    {
        return $this->isCustomDistribution ? $this->is_distribution_auto_update_enabled : $this->is_auto_update_enabled;
    }

    public function render()
    {
        $data = [];
        if ($this->isCustomDistribution) {
            $distribution = app(CoolifyDistribution::class);
            $state = $distribution->state();
            $runningImage = $distribution->runningImage();
            $repository = $state->identity->imageRepository;
            $data = [
                'distributionState' => $state,
                'runningDigest' => $repository !== null ? $runningImage?->digestFor($repository) : null,
                'runningImageReference' => $runningImage?->reference,
                'runningImageKnown' => $runningImage !== null,
                'officialLatestVersion' => $distribution->officialLatestVersion(),
                'rollbackCandidate' => $distribution->rollbackCandidate(),
                'upgradeHistory' => CoolifyUpgrade::query()->with('requestedBy:id,name')->latest('id')->limit(10)->get(),
                'environmentVersionMismatch' => $state->identity->environmentVersionMismatch(config('constants.coolify.configured_version')),
            ];
        }

        return view('livewire.settings.updates', $data);
    }
}
