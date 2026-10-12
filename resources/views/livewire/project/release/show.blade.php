<div @if (! $release->state->isTerminal() || $members->contains(fn ($member) => $member->state->isInFlight())) wire:poll.5s @endif>
    <x-slot:title>Release > {{ data_get_str($group, 'name')->limit(20) }} | Coolify</x-slot>
    <div class="w-full">
        <header class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">Release of {{ $group->name }}</h1>
                    <x-status-badge :status="$release->state->label()" :type="$release->state->badgeType()" />
                </div>
                <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                    Started {{ $release->created_at->diffForHumans() }}
                    by {{ $release->requestedBy?->name ?? str($release->trigger)->headline() }}
                </p>
            </div>
            <div class="flex w-fit shrink-0 flex-wrap items-center gap-2">
                <a href="{{ route('project.release-group.show', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'release_group_uuid' => $group->uuid]) }}"
                    {{ wireNavigate() }} class="button whitespace-nowrap">
                    <x-reicon name="layers" class="size-3.5" />
                    Release group
                </a>
                @can('release', $group)
                    @if ($release->state === \App\Enums\ReleaseState::ATTENTION_REQUIRED)
                        @if ($release->validated_at === null)
                            <x-forms.button wire:click="resume('retry')">
                                <x-reicon name="refresh" class="size-3.5" />
                                Check again
                            </x-forms.button>
                        @else
                            <x-forms.button wire:click="resume('retry')">
                                <x-reicon name="restart" class="size-3.5" />
                                Retry failed resources
                            </x-forms.button>
                            <x-forms.button wire:click="resume('continue')">
                                <x-reicon name="arrow-right" class="size-3.5" />
                                Continue without them
                            </x-forms.button>
                        @endif
                    @endif
                    @if (! $release->state->isTerminal())
                        <x-modal-confirmation title="Cancel this release?" isErrorButton buttonTitle="Cancel release"
                            submitAction="cancel" :confirmWithText="false" :confirmWithPassword="false"
                            step2ButtonText="Cancel release"
                            :actions="['Resources that have not started are not deployed.', 'Deployments still waiting in the queue are cancelled.', 'Deployments that already run are not interrupted.']" />
                    @elseif (! $group->activeRelease())
                        <x-modal-confirmation title="Run this release again?" buttonTitle="Run again"
                            submitAction="runAgain" :confirmWithText="false" :confirmWithPassword="false"
                            step2ButtonText="Run again"
                            :actions="['A new release of the group starts with the current resources and settings and the same commit.']" />
                    @endif
                @endcan
            </div>
        </header>

        <div class="flex flex-col gap-6">
            @if ($release->state === \App\Enums\ReleaseState::ATTENTION_REQUIRED && $release->attention_reason)
                <x-callout type="warning" title="The release is paused">
                    <p>{{ $release->attention_reason }}</p>
                </x-callout>
            @endif
            @if ($release->state->isTerminal() && $release->failure_summary)
                <x-callout :type="$release->state === \App\Enums\ReleaseState::CANCELLED ? 'info' : 'danger'" title="Not every resource succeeded">
                    <ul class="flex flex-col gap-1">
                        @foreach (explode("\n", $release->failure_summary) as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                </x-callout>
            @endif

            <x-application.settings-section title="Overview">
                <dl class="grid grid-cols-[minmax(0,11rem)_minmax(0,1fr)] gap-x-4 gap-y-2 text-[13px]">
                    <dt class="text-neutral-500 dark:text-fg-dim">Commit</dt>
                    <dd class="min-w-0 truncate font-mono">{{ $release->requested_commit ?? 'Configured commit of each application' }}</dd>
                    <dt class="text-neutral-500 dark:text-fg-dim">When a resource fails</dt>
                    <dd>{{ $release->failurePolicy()->label() }}</dd>
                    <dt class="text-neutral-500 dark:text-fg-dim">At the same time</dt>
                    <dd>{{ $release->maxParallel() }} resources</dd>
                    <dt class="text-neutral-500 dark:text-fg-dim">Verification</dt>
                    <dd>{{ $release->requiresHealthy() ? 'Running and healthy within '.$release->verificationTimeoutSeconds().' seconds' : 'Deployment finished' }}</dd>
                    <dt class="text-neutral-500 dark:text-fg-dim">Started</dt>
                    <dd>{{ $release->started_at?->toDayDateTimeString() ?? 'Not yet' }}</dd>
                    <dt class="text-neutral-500 dark:text-fg-dim">Finished</dt>
                    <dd>{{ $release->finished_at?->toDayDateTimeString() ?? 'Not yet' }}@if ($release->started_at && $release->finished_at) <span class="text-neutral-500 dark:text-fg-dim">({{ $release->started_at->diffForHumans($release->finished_at, true) }})</span>@endif</dd>
                    @if ($release->cancelled_at)
                        <dt class="text-neutral-500 dark:text-fg-dim">Cancelled by</dt>
                        <dd>{{ $release->cancelledBy?->name ?? 'Unknown' }}</dd>
                    @endif
                    @if ($release->notes)
                        <dt class="text-neutral-500 dark:text-fg-dim">Notes</dt>
                        <dd class="min-w-0 break-words">{{ $release->notes }}</dd>
                    @endif
                    <dt class="text-neutral-500 dark:text-fg-dim">Correlation id</dt>
                    <dd class="min-w-0 truncate font-mono text-[12px]">{{ $release->correlation_id }}</dd>
                </dl>
            </x-application.settings-section>

            <x-application.settings-section title="Resources" flush>
                <div class="data-table overflow-x-auto">
                    <div class="data-table-header release-run-table-grid">
                        <span>Application</span>
                        <span>Phase</span>
                        <span>State</span>
                        <span>Deployment</span>
                        <span>Commit</span>
                        <span>Details</span>
                    </div>
                    @foreach ($members as $member)
                        @php $application = $applications->get($member->resource_id); @endphp
                        <div wire:key="release-run-member-{{ $member->uuid }}"
                            class="data-table-row release-run-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]">
                            <span class="min-w-0">
                                <span class="block truncate text-[13px] font-medium">{{ $member->resource_name }}</span>
                                <span class="block truncate text-[12px] text-neutral-500 dark:text-fg-dim">
                                    {{ $member->is_required ? 'Required' : 'Optional' }}@if ($member->dependencies() !== []), waits for {{ collect($member->dependencies())->map(fn ($uuid) => $memberNames[$uuid] ?? 'removed resource')->implode(', ') }}@endif
                                </span>
                            </span>
                            <span class="text-[12px]">{{ $member->phase }}</span>
                            <span><x-status-badge :status="$member->state->label()" :type="$member->state->badgeType()" /></span>
                            <span class="min-w-0 truncate text-[12px]">
                                @if ($member->deployment_uuid && $application)
                                    <a class="underline underline-offset-2" {{ wireNavigate() }}
                                        href="{{ route('project.application.deployment.show', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'application_uuid' => $application->uuid, 'deployment_uuid' => $member->deployment_uuid]) }}">{{ $member->deployment_status ?? 'Open' }}</a>
                                @else
                                    <span class="text-neutral-500 dark:text-fg-dim">None</span>
                                @endif
                            </span>
                            <span class="min-w-0 truncate font-mono text-[12px]">{{ $member->actual_commit ?? $member->requested_commit ?? '' }}</span>
                            <span class="min-w-0 text-[12px] text-neutral-600 dark:text-fg-dim">
                                {{ $member->failure_reason ?? $member->note }}
                                @if ($member->state === \App\Enums\ReleaseMemberState::VERIFYING && $member->verification)
                                    Last status: {{ data_get($member->verification, 'container_status') }}.
                                @endif
                                @if ($member->attempts > 1)
                                    Attempt {{ $member->attempts }}.
                                @endif
                            </span>
                        </div>
                    @endforeach
                </div>
            </x-application.settings-section>

            @if ($preflight !== [])
                <x-application.settings-section title="Checks before the release">
                    <ul class="flex flex-col gap-3 text-[13px]">
                        @foreach ($preflight as $check)
                            <li class="flex flex-col gap-1 sm:flex-row sm:items-start sm:gap-3">
                                <span class="w-40 shrink-0"><x-status-badge :status="$check->label" :type="$check->state->badgeType()" /></span>
                                <span class="min-w-0">
                                    {{ $check->summary }}
                                    @if ($check->remediation && $check->blocks())
                                        <span class="block text-neutral-500 dark:text-fg-dim">{{ $check->remediation }}</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </x-application.settings-section>
            @endif
        </div>
    </div>
</div>
