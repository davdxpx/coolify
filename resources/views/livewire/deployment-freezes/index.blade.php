<div>
    <x-slot:title>Deployment freezes | Coolify</x-slot>
    <div class="w-full">
        <header class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">Deployment freezes</h1>
                <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                    While a freeze is active, no deployment of the covered applications starts: not from the UI, the API, Git webhooks or releases. Times are in {{ $timezone }}.
                </p>
            </div>
        </header>

        <div class="flex flex-col gap-6">
            <x-application.settings-section title="Active and planned" flush>
                @if ($current->isEmpty())
                    <div class="p-6">
                        <x-empty size="sm" title="No freeze planned" description="Deployments run normally." icon-name="calendar" />
                    </div>
                @else
                    <div class="data-table overflow-x-auto">
                        <div class="data-table-header deployment-freezes-table-grid">
                            <span>Scope</span>
                            <span>Period</span>
                            <span>Reason</span>
                            <span>Exceptions</span>
                            <span></span>
                        </div>
                        @foreach ($current as $freeze)
                            <div wire:key="freeze-{{ $freeze->uuid }}"
                                class="data-table-row deployment-freezes-table-grid items-center border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]">
                                <span class="min-w-0">
                                    <span class="block truncate text-[13px] font-medium">
                                        @if ($freeze->environment)
                                            {{ $freeze->project?->name }} / {{ $freeze->environment->name }}
                                        @elseif ($freeze->project)
                                            Project {{ $freeze->project->name }}
                                        @else
                                            Whole team
                                        @endif
                                    </span>
                                    @if ($freeze->isActive())
                                        <x-status-badge status="Active" type="error" />
                                    @else
                                        <x-status-badge status="Planned" type="warning" />
                                    @endif
                                </span>
                                <span class="text-[12px] text-neutral-600 dark:text-fg-dim">
                                    {{ $freeze->starts_at->timezone($timezone)->toDayDateTimeString() }}<br>
                                    until {{ $freeze->ends_at?->timezone($timezone)->toDayDateTimeString() ?? 'ended manually' }}
                                </span>
                                <span class="min-w-0 break-words text-[12px]">{{ $freeze->reason }}</span>
                                <span class="text-[12px] text-neutral-600 dark:text-fg-dim">
                                    {{ $freeze->allow_rollbacks ? 'Rollbacks allowed.' : 'No rollbacks.' }}
                                    {{ $freeze->allow_override ? 'Admins can override with a reason.' : 'No override.' }}
                                </span>
                                <span class="flex justify-end">
                                    @can('update', $freeze)
                                        <x-modal-confirmation title="End this freeze?" buttonTitle="End freeze"
                                            submitAction="end('{{ $freeze->uuid }}')" :confirmWithText="false" :confirmWithPassword="false"
                                            step2ButtonText="End freeze" :actions="['Deployments of the covered applications can start again.']" />
                                    @endcan
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-application.settings-section>

            @can('create', \App\Models\DeploymentFreeze::class)
                <form wire:submit="create">
                    <x-application.settings-section title="New freeze">
                        <div class="grid gap-4 lg:grid-cols-2">
                            <x-forms.input id="reason" label="Reason" required placeholder="Year-end freeze" />
                            <x-forms.listbox id="scope" label="Applies to" searchable :options="$scopes" />
                            <x-forms.input type="datetime-local" id="startsAt" label="Starts" required />
                            <x-forms.input type="datetime-local" id="endsAt" label="Ends" helper="Leave empty to keep the freeze until someone ends it." />
                            <div class="flex flex-col gap-1">
                                <x-forms.checkbox id="allowRollbacks" label="Allow rollbacks to an earlier deployment" />
                                <x-forms.checkbox id="allowOverride" label="Allow admins to override with a reason (audited)" />
                            </div>
                        </div>
                        <div class="mt-4">
                            <x-forms.button type="submit">
                                <x-reicon name="plus" class="size-3.5" />
                                Create freeze
                            </x-forms.button>
                        </div>
                    </x-application.settings-section>
                </form>
            @endcan

            @if ($past->isNotEmpty())
                <x-application.settings-section title="Past freezes" flush>
                    <div class="data-table overflow-x-auto">
                        <div class="data-table-header deployment-freezes-past-table-grid">
                            <span>Scope</span>
                            <span>Period</span>
                            <span>Reason</span>
                        </div>
                        @foreach ($past as $freeze)
                            <div wire:key="freeze-past-{{ $freeze->uuid }}"
                                class="data-table-row deployment-freezes-past-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]">
                                <span class="min-w-0 truncate text-[12px]">{{ $freeze->environment ? $freeze->project?->name.' / '.$freeze->environment->name : ($freeze->project ? 'Project '.$freeze->project->name : 'Whole team') }}</span>
                                <span class="text-[12px] text-neutral-600 dark:text-fg-dim">{{ $freeze->starts_at->timezone($timezone)->toDayDateTimeString() }} until {{ ($freeze->ended_at ?? $freeze->ends_at)?->timezone($timezone)->toDayDateTimeString() }}</span>
                                <span class="min-w-0 truncate text-[12px]">{{ $freeze->reason }}</span>
                            </div>
                        @endforeach
                    </div>
                </x-application.settings-section>
            @endif
        </div>
    </div>
</div>
