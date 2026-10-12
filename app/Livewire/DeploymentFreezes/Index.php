<?php

namespace App\Livewire\DeploymentFreezes;

use App\Models\DeploymentFreeze;
use App\Models\Environment;
use App\Models\Project;
use App\Services\Deployment\DeploymentFreezes;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    public string $reason = '';

    public string $scope = 'team';

    public ?string $startsAt = null;

    public ?string $endsAt = null;

    public bool $allowRollbacks = true;

    public bool $allowOverride = true;

    public function mount(): void
    {
        $this->startsAt = now($this->timezone())->format('Y-m-d\TH:i');
    }

    private function timezone(): string
    {
        return instanceSettings()->instance_timezone ?: config('app.timezone');
    }

    public function create()
    {
        try {
            $this->authorize('create', DeploymentFreeze::class);
            $this->validate([
                'reason' => 'required|string|max:255',
                'scope' => 'required|string',
                'startsAt' => 'required|date_format:Y-m-d\TH:i',
                'endsAt' => 'nullable|date_format:Y-m-d\TH:i',
                'allowRollbacks' => 'boolean',
                'allowOverride' => 'boolean',
            ]);

            $timezone = $this->timezone();
            $startsAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $this->startsAt, $timezone)->utc();
            $endsAt = filled($this->endsAt) ? CarbonImmutable::createFromFormat('Y-m-d\TH:i', $this->endsAt, $timezone)->utc() : null;
            if ($endsAt !== null && $endsAt->lte($startsAt)) {
                $this->addError('endsAt', 'The end must be after the start.');

                return;
            }

            [$projectId, $environmentId] = $this->resolveScope();
            $freeze = DeploymentFreeze::create([
                'team_id' => currentTeam()->id,
                'project_id' => $projectId,
                'environment_id' => $environmentId,
                'reason' => trim($this->reason),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'allow_rollbacks' => $this->allowRollbacks,
                'allow_override' => $this->allowOverride,
                'created_by_user_id' => auth()->id(),
            ]);
            DeploymentFreezes::recordEvent($freeze, 'deployment_freeze.created');
            auditLog('ui.deployment_freeze.created', [
                'team_id' => $freeze->team_id,
                'freeze_uuid' => $freeze->uuid,
                'scope' => $freeze->scope(),
                'starts_at' => $freeze->starts_at->toIso8601String(),
                'ends_at' => $freeze->ends_at?->toIso8601String(),
            ]);
            $this->reset('reason', 'endsAt');
            $this->dispatch('success', 'Deployment freeze created.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function end(string $uuid)
    {
        try {
            $freeze = DeploymentFreeze::query()->where('team_id', currentTeam()->id)->where('uuid', $uuid)->firstOrFail();
            $this->authorize('update', $freeze);
            if ($freeze->ended_at !== null) {
                return;
            }
            $freeze->update(['ended_at' => now(), 'ended_by_user_id' => auth()->id()]);
            DeploymentFreezes::recordEvent($freeze, 'deployment_freeze.ended');
            auditLog('ui.deployment_freeze.ended', ['team_id' => $freeze->team_id, 'freeze_uuid' => $freeze->uuid]);
            $this->dispatch('success', 'Deployment freeze ended.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function resolveScope(): array
    {
        if ($this->scope === 'team') {
            return [null, null];
        }
        [$type, $uuid] = array_pad(explode(':', $this->scope, 2), 2, '');
        if ($type === 'project') {
            return [Project::ownedByCurrentTeam()->where('uuid', $uuid)->firstOrFail()->id, null];
        }
        if ($type === 'environment') {
            $environment = Environment::ownedByCurrentTeam()->where('uuid', $uuid)->firstOrFail();

            return [$environment->project_id, $environment->id];
        }

        abort(422, 'Unknown scope.');
    }

    public function render()
    {
        $teamId = currentTeam()->id;
        $projects = Project::ownedByCurrentTeam()->with('environments')->get();
        $scopes = [['value' => 'team', 'label' => 'Whole team']];
        foreach ($projects as $project) {
            $scopes[] = ['value' => "project:{$project->uuid}", 'label' => "Project {$project->name}"];
            foreach ($project->environments as $environment) {
                $scopes[] = ['value' => "environment:{$environment->uuid}", 'label' => "{$project->name} / {$environment->name}"];
            }
        }

        return view('livewire.deployment-freezes.index', [
            'current' => DeploymentFreeze::query()->where('team_id', $teamId)->upcomingOrActive()->with(['project', 'environment', 'createdBy'])->orderBy('starts_at')->get(),
            'past' => DeploymentFreeze::query()->where('team_id', $teamId)
                ->where(fn ($query) => $query->whereNotNull('ended_at')->orWhere('ends_at', '<=', now()))
                ->with(['project', 'environment'])->latest('starts_at')->limit(20)->get(),
            'scopes' => $scopes,
            'timezone' => $this->timezone(),
        ]);
    }
}
