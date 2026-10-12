<div>
    <x-slot:title>{{ data_get_str($group, 'name')->limit(20) }} > Releases | Coolify</x-slot>
    <div class="w-full">
        <header class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">{{ $group->name }}</h1>
                <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                    Release group in {{ $environment->name }}@if ($group->description), {{ $group->description }}@endif
                </p>
            </div>
            <div class="flex w-fit shrink-0 items-center gap-2">
                <a href="{{ route('project.release-group.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}"
                    {{ wireNavigate() }} class="button whitespace-nowrap">
                    <x-reicon name="unordered-list" class="size-3.5" />
                    All release groups
                </a>
                @if ($activeRelease)
                    <a href="{{ route('project.release.show', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'release_group_uuid' => $group->uuid, 'release_uuid' => $activeRelease->uuid]) }}"
                        {{ wireNavigate() }} class="button button-highlighted whitespace-nowrap">
                        <x-reicon name="play-circle" class="size-3.5" />
                        Open running release
                    </a>
                @endif
            </div>
        </header>

        <div class="flex flex-col gap-6">
            @can('release', $group)
                @if (! $activeRelease && $group->is_enabled && $members->where('is_enabled', true)->isNotEmpty())
                    <x-application.settings-section title="Start a release"
                        helper="Every enabled resource is deployed through its server's normal deployment queue. Phases run one after another; within a phase, dependencies decide the order.">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-forms.input id="releaseCommit" label="Commit or tag"
                                placeholder="Leave empty to deploy each application's configured commit"
                                helper="Used for every application that builds from Git. Applications that run a prebuilt image keep their configured tag." />
                            <x-forms.input id="releaseNotes" label="Notes" placeholder="What is in this release?" />
                        </div>
                        <div class="mt-4">
                            <x-modal-confirmation title="Start this release?" buttonTitle="Start release" isHighlightedButton
                                submitAction="startRelease" :confirmWithText="false" :confirmWithPassword="false"
                                step2ButtonText="Start release"
                                :actions="[
                                    $members->where('is_enabled', true)->count().' resources are deployed in '.$members->where('is_enabled', true)->pluck('phase')->unique()->count().' phase(s).',
                                    'If a resource fails: '.lcfirst($group->failure_policy->description()),
                                ]" />
                        </div>
                    </x-application.settings-section>
                @elseif (! $group->is_enabled)
                    <x-callout type="warning" title="Release group disabled">
                        <p>Enable the group in its settings below to start releases.</p>
                    </x-callout>
                @endif
            @endcan

            <x-application.settings-section title="Resources" flush
                helper="Resources in an earlier phase finish before the next phase starts. A dependency waits for the named resource to succeed; if it fails, the dependent resource is skipped.">
                @if ($members->isEmpty())
                    <div class="p-6">
                        <x-empty size="sm" title="No resources yet" description="Add the applications that belong to this release." icon-name="layers" />
                    </div>
                @else
                    <form wire:submit="saveMembers">
                        <div class="data-table overflow-x-auto">
                            <div class="data-table-header release-members-table-grid">
                                <span>Application</span>
                                <span>Phase</span>
                                <span>Waits for</span>
                                <span>Required</span>
                                <span>Rebuild</span>
                                <span>Enabled</span>
                                <span></span>
                            </div>
                            @foreach ($members as $member)
                                @php
                                    $application = $applications->get($member->resource_id);
                                    $dependencyOptions = $members->where('uuid', '!=', $member->uuid)
                                        ->map(fn ($other) => ['value' => $other->uuid, 'label' => $applications->get($other->resource_id)?->name ?? 'Removed resource'])
                                        ->values()->all();
                                @endphp
                                <div wire:key="release-member-{{ $member->uuid }}"
                                    class="data-table-row release-members-table-grid items-center border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]">
                                    <span class="min-w-0">
                                        @if ($application)
                                            <a class="block truncate text-[13px] font-medium underline-offset-2 hover:underline" {{ wireNavigate() }}
                                                href="{{ route('project.application.configuration', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'application_uuid' => $application->uuid]) }}">{{ $application->name }}</a>
                                            <span class="block truncate text-[12px] text-neutral-500 dark:text-fg-dim">{{ $application->build_pack }}</span>
                                        @else
                                            <span class="text-[13px] text-red-600 dark:text-red-400">Removed application</span>
                                        @endif
                                    </span>
                                    <span><x-forms.input type="number" min="1" max="20" id="memberSettings.{{ $member->uuid }}.phase" canGate="update" :canResource="$group" /></span>
                                    <span class="min-w-0">
                                        <x-forms.listbox multiple id="memberSettings.{{ $member->uuid }}.depends_on" placeholder="Nothing"
                                            :options="$dependencyOptions" emptyText="No other resources" canGate="update" :canResource="$group" />
                                    </span>
                                    <span><x-forms.checkbox id="memberSettings.{{ $member->uuid }}.is_required" :disabled="auth()->user()->cannot('update', $group)" /></span>
                                    <span><x-forms.checkbox id="memberSettings.{{ $member->uuid }}.force_rebuild" :disabled="auth()->user()->cannot('update', $group)" /></span>
                                    <span><x-forms.checkbox id="memberSettings.{{ $member->uuid }}.is_enabled" :disabled="auth()->user()->cannot('update', $group)" /></span>
                                    <span class="flex justify-end">
                                        @can('update', $group)
                                            <button type="button" class="button" wire:click="removeMember('{{ $member->uuid }}')"
                                                aria-label="Remove {{ $application?->name ?? 'resource' }} from the group">
                                                <x-reicon name="trash" class="size-3.5" />
                                            </button>
                                        @endcan
                                    </span>
                                </div>
                            @endforeach
                        </div>
                        @can('update', $group)
                            <div class="border-t border-neutral-200 p-4 dark:border-white/[0.08]">
                                <x-forms.button type="submit">Save resources</x-forms.button>
                            </div>
                        @endcan
                    </form>
                @endif
                @can('update', $group)
                    <form wire:submit="addMember" class="flex flex-col gap-3 border-t border-neutral-200 p-4 sm:flex-row sm:items-end dark:border-white/[0.08]">
                        <div class="min-w-0 flex-1">
                            <x-forms.listbox id="newApplicationUuid" label="Add application" searchable
                                placeholder="Choose an application"
                                emptyText="Every application of this environment is already in the group."
                                :options="$availableApplications->map(fn ($application) => ['value' => $application->uuid, 'label' => $application->name])->all()" />
                        </div>
                        <div class="w-full sm:w-28">
                            <x-forms.input type="number" min="1" max="20" id="newPhase" label="Phase" />
                        </div>
                        <x-forms.button type="submit">
                            <x-reicon name="plus" class="size-3.5" />
                            Add
                        </x-forms.button>
                    </form>
                @endcan
            </x-application.settings-section>

            <form wire:submit="saveSettings">
                <x-application.settings-section title="Settings">
                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.input id="name" label="Name" required canGate="update" :canResource="$group" />
                        <x-forms.input id="description" label="Description" canGate="update" :canResource="$group" />
                        <x-forms.listbox id="failurePolicy" label="When a required resource fails"
                            canGate="update" :canResource="$group"
                            :options="collect($policies)->map(fn ($policy) => ['value' => $policy->value, 'label' => $policy->label(), 'description' => $policy->description()])->all()" />
                        <x-forms.input type="number" min="1" max="{{ \App\Models\ReleaseGroup::MAX_PARALLEL_LIMIT }}" id="maxParallel"
                            label="Resources deployed at the same time" canGate="update" :canResource="$group"
                            helper="Server limits for concurrent builds still apply." />
                        <x-forms.input type="number" min="60" max="7200" id="verificationTimeoutSeconds"
                            label="Verification timeout (seconds)" canGate="update" :canResource="$group"
                            helper="How long a finished deployment may take to report a running and healthy state." />
                        <div class="flex flex-col gap-1 pt-6">
                            <x-forms.checkbox id="requireHealthy" label="Wait until each application runs and is healthy" :disabled="auth()->user()->cannot('update', $group)" />
                            <x-forms.checkbox id="isEnabled" label="Group enabled" :disabled="auth()->user()->cannot('update', $group)" />
                        </div>
                    </div>
                    <h4 class="mt-6 mb-2 text-sm font-semibold">Checks before a release</h4>
                    <p class="mb-3 text-[12px] text-neutral-500 dark:text-fg-dim">A failed check pauses the release before the first deployment. Admins can override it with a reason that is audited. Active deployment freezes are always checked.</p>
                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.input type="number" min="1" max="720" id="backupMaxAgeHours" label="Required database backup age (hours)"
                            canGate="update" :canResource="$group"
                            helper="Empty: not checked. Otherwise every database of the environment needs a successful backup that is not older." />
                        <div class="pt-6">
                            <x-forms.checkbox id="windowEnabled" live label="Only release inside a release window" :disabled="auth()->user()->cannot('update', $group)" />
                        </div>
                        @if ($windowEnabled)
                            <x-forms.listbox multiple id="windowDays" label="Days" canGate="update" :canResource="$group"
                                :options="collect(range(1, 7))->map(fn ($day) => ['value' => (string) $day, 'label' => \Illuminate\Support\Carbon::create(2024, 1, $day)->format('l')])->all()" />
                            <x-forms.listbox id="windowTimezone" label="Time zone" searchable canGate="update" :canResource="$group"
                                :options="collect(timezone_identifiers_list())->map(fn ($zone) => ['value' => $zone, 'label' => $zone])->all()" />
                            <x-forms.input type="time" id="windowStart" label="From" canGate="update" :canResource="$group" />
                            <x-forms.input type="time" id="windowEnd" label="Until" canGate="update" :canResource="$group" />
                        @endif
                    </div>
                    @can('update', $group)
                        <div class="mt-4">
                            <x-forms.button type="submit">Save settings</x-forms.button>
                        </div>
                    @endcan
                </x-application.settings-section>
            </form>

            <x-application.settings-section title="Release history" flush>
                @if ($releases->isEmpty())
                    <div class="p-6">
                        <x-empty size="sm" title="No releases yet" description="Started releases appear here with their result." icon-name="time-back" />
                    </div>
                @else
                    <div class="data-table overflow-x-auto">
                        <div class="data-table-header release-history-table-grid">
                            <span>Started</span>
                            <span>Result</span>
                            <span>Commit</span>
                            <span>By</span>
                            <span>Notes</span>
                        </div>
                        @foreach ($releases as $release)
                            <a wire:key="release-{{ $release->uuid }}" {{ wireNavigate() }}
                                href="{{ route('project.release.show', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'release_group_uuid' => $group->uuid, 'release_uuid' => $release->uuid]) }}"
                                class="data-table-row release-history-table-grid border-b border-neutral-200 last:border-b-0 hover:bg-neutral-50 dark:border-white/[0.08] dark:hover:bg-white/[0.03]">
                                <span class="text-[12px] text-neutral-600 dark:text-fg-dim">{{ $release->created_at->toDayDateTimeString() }}</span>
                                <span><x-status-badge :status="$release->state->label()" :type="$release->state->badgeType()" /></span>
                                <span class="min-w-0 truncate font-mono text-[12px]">{{ $release->requested_commit ?? 'Configured' }}</span>
                                <span class="min-w-0 truncate text-[12px] text-neutral-600 dark:text-fg-dim">{{ $release->requestedBy?->name ?? str($release->trigger)->headline() }}</span>
                                <span class="min-w-0 truncate text-[12px] text-neutral-600 dark:text-fg-dim">{{ $release->notes }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </x-application.settings-section>

            @can('delete', $group)
                <section class="overflow-hidden rounded-[10px] border border-red-300 bg-red-50/80 dark:border-red-500/25 dark:bg-red-500/[0.06]">
                    <div class="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <h2 class="text-sm font-semibold text-red-800 dark:text-red-300">Delete release group</h2>
                            <p class="mt-1 max-w-2xl text-sm text-red-700/80 dark:text-red-200/70">
                                Removes the group and its release history. The applications are not changed.
                            </p>
                        </div>
                        <div class="shrink-0 sm:pt-0.5">
                            <x-modal-confirmation title="Delete this release group?" isErrorButton buttonTitle="Delete release group"
                                submitAction="delete" :confirmWithText="true" :confirmationText="$group->name"
                                confirmationLabel="Enter the name of the release group to confirm" shortConfirmationLabel="Release group name"
                                :confirmWithPassword="false" step2ButtonText="Delete release group"
                                :actions="['The release group and its release history are deleted.']" />
                        </div>
                    </div>
                </section>
            @endcan
        </div>
    </div>
</div>
