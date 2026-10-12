<?php

namespace App\Livewire;

use App\Actions\Distribution\StartDistributionUpgrade;
use App\Actions\Server\UpdateCoolify;
use App\Enums\CoolifyUpgradeState;
use App\Models\CoolifyUpgrade;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Services\CoolifyUpgradeStatus;
use App\Services\Distribution\CoolifyDistribution;
use App\Services\Distribution\DistributionUpgradePreflight;
use App\Services\Distribution\DistributionUpgradeRefused;
use App\Services\Preflight\PreflightResult;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Upgrade extends Component
{
    public bool $updateInProgress = false;

    public bool $isUpgradeAvailable = false;

    public string $latestVersion = '';

    public string $currentVersion = '';

    public bool $devMode = false;

    public bool $fullButton = false;

    #[Locked]
    public bool $isCustomDistribution = false;

    #[Locked]
    public ?int $targetBuild = null;

    #[Locked]
    public ?string $upgradeUuid = null;

    protected $listeners = ['updateAvailable' => 'checkUpdate'];

    public function mount()
    {
        $this->refreshUpgradeState();
    }

    public function checkUpdate()
    {
        try {
            $this->refreshUpgradeState();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    protected function refreshUpgradeState(): void
    {
        $distribution = app(CoolifyDistribution::class);
        if ($distribution->isCustom()) {
            $state = $distribution->state();
            $this->isCustomDistribution = true;
            $this->devMode = false;
            $this->currentVersion = $state->runningLabel();
            $this->latestVersion = $state->candidate?->version ?? $this->currentVersion;
            $this->targetBuild = $state->updateAvailable ? $state->candidate?->build : null;
            $this->isUpgradeAvailable = $state->updateAvailable;

            return;
        }

        $this->currentVersion = config('constants.coolify.version');
        $this->latestVersion = get_latest_version_of_coolify();
        $this->devMode = isDev();

        if ($this->devMode) {
            $this->isUpgradeAvailable = true;

            return;
        }

        $settings = InstanceSettings::find(0);
        $hasNewerVersion = version_compare($this->latestVersion, $this->currentVersion, '>');
        $newVersionAvailable = (bool) data_get($settings, 'new_version_available', false);

        if ($settings && $newVersionAvailable && ! $hasNewerVersion) {
            $settings->update(['new_version_available' => false]);
            $newVersionAvailable = false;
        }

        $this->isUpgradeAvailable = $hasNewerVersion && $newVersionAvailable;
    }

    /**
     * @return array{target: array<string, mixed>|null, checks: list<array<string, mixed>>, blocked: bool}
     */
    public function preflight(): array
    {
        if (! isInstanceAdmin() || ! $this->isCustomDistribution) {
            abort(403);
        }

        $state = app(CoolifyDistribution::class)->state();
        $candidate = $state->updateAvailable ? $state->candidate : null;
        if ($candidate === null) {
            return ['target' => null, 'checks' => [], 'blocked' => true];
        }

        $this->targetBuild = $candidate->build;
        $results = app(DistributionUpgradePreflight::class)->run($candidate);

        return [
            'target' => [
                'version' => $candidate->version,
                'build' => $candidate->build,
                'base_version' => $candidate->baseVersion,
                'commit' => $candidate->shortCommit(),
                'digest' => $candidate->digest,
            ],
            'checks' => array_map(fn (PreflightResult $result) => $result->toArray() + ['blocks' => $result->blocks()], $results),
            'blocked' => PreflightResult::blocking(array_filter($results, fn (PreflightResult $result) => ! $result->overridable)) !== [],
        ];
    }

    /**
     * @param  list<string>  $acknowledged
     */
    public function upgrade(array $acknowledged = [], string $reason = ''): ?bool
    {
        try {
            if (! isInstanceAdmin()) {
                abort(403);
            }
            if ($this->updateInProgress) {
                return null;
            }

            if ($this->isCustomDistribution) {
                return $this->startDistributionUpgrade($acknowledged, $reason);
            }

            $this->updateInProgress = true;
            dispatch(function () {
                try {
                    UpdateCoolify::run(manual_update: true);
                } catch (\Throwable $e) {
                    report($e);
                }
            })->afterResponse();

            auditLog('ui.instance.upgrade_started', [
                'team_id' => null,
                'resource' => 'instance',
                'from_version' => config('constants.coolify.version'),
                'to_version' => get_latest_version_of_coolify(),
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }

    /**
     * @param  list<string>  $acknowledged
     */
    private function startDistributionUpgrade(array $acknowledged, string $reason): bool
    {
        $state = app(CoolifyDistribution::class)->state();
        $candidate = $state->updateAvailable ? $state->candidate : null;
        if ($candidate === null || $candidate->build !== $this->targetBuild) {
            $this->dispatch('error', 'The available release changed. Review the update again.');

            return false;
        }

        try {
            $upgrade = StartDistributionUpgrade::run(
                $candidate,
                CoolifyUpgrade::TRIGGER_MANUAL,
                auth()->user(),
                array_values(array_filter($acknowledged, 'is_string')),
                mb_substr($reason, 0, 1000),
            );
        } catch (DistributionUpgradeRefused $e) {
            $this->dispatch('error', $e->getMessage());

            return false;
        }

        $this->updateInProgress = true;
        $this->upgradeUuid = $upgrade->uuid;

        return true;
    }

    public function getUpgradeStatus(): array
    {
        // Only root team members can view upgrade status
        if (auth()->user()?->currentTeam()?->id !== 0) {
            return ['status' => 'none'];
        }

        if ($this->isCustomDistribution) {
            $status = $this->distributionUpgradeStatus();
            if ($status !== null) {
                return $status;
            }
        }

        $server = Server::find(0);
        if (! $server) {
            return ['status' => 'none'];
        }

        $statusFile = CoolifyUpgradeStatus::FILE;

        try {
            $content = instant_remote_process(
                ["cat {$statusFile} 2>/dev/null || echo ''"],
                $server,
                false
            );
            $content = trim($content ?? '');
        } catch (\Throwable $e) {
            return ['status' => 'none'];
        }

        if ($this->isCustomDistribution) {
            $status = CoolifyUpgradeStatus::fromFile($content, '', '');

            return $status['status'] !== 'error' && ($status['step'] ?? 0) >= 6
                ? ['status' => 'in_progress', 'step' => 6, 'message' => 'Verifying the new version…']
                : $status;
        }

        return CoolifyUpgradeStatus::fromFile(
            content: $content,
            runningVersion: $this->currentVersion !== '' ? $this->currentVersion : (string) config('constants.coolify.version'),
            targetVersion: $this->latestVersion !== '' ? $this->latestVersion : get_latest_version_of_coolify(),
        );
    }

    /**
     * @return array{status: string, step?: int, message?: string}|null
     */
    private function distributionUpgradeStatus(): ?array
    {
        $upgrade = $this->upgradeUuid !== null
            ? CoolifyUpgrade::query()->where('uuid', $this->upgradeUuid)->first()
            : CoolifyUpgrade::query()->latest('id')->first();

        if ($upgrade === null || $upgrade->state === CoolifyUpgradeState::RUNNING) {
            return null;
        }

        if ($upgrade->finished_at !== null && $upgrade->finished_at->lt(now()->subMinutes(30))) {
            return null;
        }

        return $upgrade->state === CoolifyUpgradeState::SUCCEEDED
            ? ['status' => 'complete', 'step' => 6, 'message' => "Coolify {$upgrade->to_version} is running and verified."]
            : ['status' => 'error', 'step' => 0, 'message' => $upgrade->state->label().'. '.$upgrade->message];
    }
}
