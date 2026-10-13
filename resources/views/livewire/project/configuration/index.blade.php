<div>
    <x-slot:title>{{ data_get_str($environment, 'name')->limit(10) }} > Configuration | Coolify</x-slot>
    <div class="w-full">
        <header class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">Configuration</h1>
                <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                    What changed and is not deployed yet in {{ $environment->name }}. Values are never shown on this page.
                </p>
            </div>
            <div class="flex w-fit shrink-0 items-center gap-2">
                <a href="{{ route('project.resource.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}"
                    {{ wireNavigate() }} class="button whitespace-nowrap">
                    <x-reicon name="layers" class="size-3.5" />
                    Resources
                </a>
                <a href="{{ route('shared-variables.environment.show', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}"
                    {{ wireNavigate() }} class="button whitespace-nowrap">
                    <x-reicon name="variables" class="size-3.5" />
                    Shared variables
                </a>
            </div>
        </header>

        @php $summary = $report['summary']; @endphp
        <div class="flex flex-col gap-6">
            <x-application.settings-section title="Summary">
                <dl class="grid grid-cols-[minmax(0,1fr)] gap-3 text-[13px] sm:grid-cols-3">
                    <div><dt class="text-neutral-500 dark:text-fg-dim">Applications with undeployed changes</dt><dd class="text-[20px] font-semibold">{{ $summary['pending_changes'] }} <span class="text-[13px] font-normal text-neutral-500 dark:text-fg-dim">of {{ $summary['resources'] }}</span></dd></div>
                    <div><dt class="text-neutral-500 dark:text-fg-dim">Shared variables changed after the last deployment</dt><dd class="text-[20px] font-semibold">{{ $summary['stale_shared_references'] }}</dd></div>
                    <div><dt class="text-neutral-500 dark:text-fg-dim">References to missing shared variables</dt><dd class="text-[20px] font-semibold">{{ $summary['missing_shared_references'] }}</dd></div>
                    <div><dt class="text-neutral-500 dark:text-fg-dim">Contract violations</dt><dd class="text-[20px] font-semibold">{{ $summary['contract_violations'] }}</dd></div>
                    <div><dt class="text-neutral-500 dark:text-fg-dim">Secrets older than {{ $report['rotation_days'] }} days</dt><dd class="text-[20px] font-semibold">{{ $summary['secrets_due_for_rotation'] }}</dd></div>
                </dl>
            </x-application.settings-section>

            <x-application.settings-section title="Applications" flush>
                @if ($report['resources'] === [])
                    <div class="p-6"><x-empty size="sm" title="No applications" description="This environment has no applications." icon-name="layers" /></div>
                @else
                    <div class="data-table overflow-x-auto">
                        <div class="data-table-header configuration-report-table-grid">
                            <span>Application</span>
                            <span>Not deployed</span>
                            <span>Shared variables</span>
                            <span>Contract</span>
                            <span>Rotation</span>
                        </div>
                        @foreach ($report['resources'] as $resource)
                            <div wire:key="configuration-{{ $resource['resource_uuid'] }}"
                                class="data-table-row configuration-report-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]">
                                <span class="min-w-0 truncate text-[13px] font-medium">{{ $resource['resource_name'] }}</span>
                                <span class="min-w-0 text-[12px] text-neutral-600 dark:text-fg-dim">
                                    @if ($resource['pending_changes']['changed'])
                                        <x-status-badge :status="$resource['pending_changes']['requires_build'] ? 'Rebuild needed' : 'Redeploy needed'" type="warning" />
                                        <span class="block">{{ collect($resource['pending_changes']['items'])->pluck('label')->filter()->take(4)->implode(', ') }}@if (count($resource['pending_changes']['items']) > 4), and {{ count($resource['pending_changes']['items']) - 4 }} more @endif</span>
                                    @elseif ($resource['pending_changes']['changed'] === false)
                                        Up to date
                                    @else
                                        Unknown
                                    @endif
                                </span>
                                <span class="min-w-0 text-[12px] text-neutral-600 dark:text-fg-dim">
                                    @foreach ($resource['stale_shared_references'] as $reference)
                                        <span class="block">{{ $reference['variable_key'] }}: {{ $reference['shared'] }} changed after the last deployment</span>
                                    @endforeach
                                    @foreach ($resource['missing_shared_references'] as $reference)
                                        <span class="block text-red-600 dark:text-red-400">{{ $reference['variable_key'] }}: {{ $reference['shared'] }} does not exist</span>
                                    @endforeach
                                    @if ($resource['stale_shared_references'] === [] && $resource['missing_shared_references'] === [])
                                        Current
                                    @endif
                                </span>
                                <span class="min-w-0 text-[12px] text-neutral-600 dark:text-fg-dim">
                                    @if ($resource['contract'] === null)
                                        None
                                    @elseif ($resource['contract']['missing'] === [] && $resource['contract']['empty'] === [] && $resource['contract']['unresolved'] === [])
                                        Fulfilled
                                    @else
                                        @if ($resource['contract']['missing'] !== [])<span class="block text-red-600 dark:text-red-400">Missing: {{ implode(', ', $resource['contract']['missing']) }}</span>@endif
                                        @if ($resource['contract']['empty'] !== [])<span class="block text-red-600 dark:text-red-400">Empty: {{ implode(', ', $resource['contract']['empty']) }}</span>@endif
                                        @if ($resource['contract']['unresolved'] !== [])<span class="block text-red-600 dark:text-red-400">Unresolved: {{ implode(', ', $resource['contract']['unresolved']) }}</span>@endif
                                    @endif
                                </span>
                                <span class="min-w-0 text-[12px] text-neutral-600 dark:text-fg-dim">
                                    {{ collect($resource['secrets_due_for_rotation'])->map(fn ($secret) => $secret['key'].' ('.$secret['age_days'].' days)')->implode(', ') ?: 'Nothing due' }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-application.settings-section>

            <x-application.settings-section title="Shared variables in this environment" flush>
                @if ($report['shared_variables'] === [])
                    <div class="p-6"><x-empty size="sm" title="No shared variables" description="Team, project and environment variables defined for this environment appear here." icon-name="variables" /></div>
                @else
                    <div class="data-table overflow-x-auto">
                        <div class="data-table-header configuration-shared-table-grid">
                            <span>Reference</span>
                            <span>Changed</span>
                            <span>Used by</span>
                        </div>
                        @foreach ($report['shared_variables'] as $shared)
                            <div wire:key="shared-{{ $shared['id'] }}"
                                class="data-table-row configuration-shared-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]">
                                <span class="min-w-0 truncate font-mono text-[12px]">{{ "\u{7B}\u{7B}".$shared['reference']."\u{7D}\u{7D}" }}@if ($shared['rotation_due']) <x-status-badge status="Rotation due" type="warning" />@endif</span>
                                <span class="text-[12px] text-neutral-600 dark:text-fg-dim">{{ $shared['updated_at'] ? \Illuminate\Support\Carbon::parse($shared['updated_at'])->diffForHumans() : '' }}</span>
                                <span class="min-w-0 text-[12px] text-neutral-600 dark:text-fg-dim">
                                    {{ collect($shared['used_by'])->map(fn ($use) => $use['resource_name'].($use['stale'] ? ' (not deployed)' : ''))->unique()->implode(', ') ?: 'Not used here' }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-application.settings-section>
        </div>
    </div>
</div>
