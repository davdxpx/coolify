<div>
    <x-slot:title>{{ data_get_str($environment, 'name')->limit(10) }} > Releases | Coolify</x-slot>
    <div class="w-full">
        <header class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">Releases</h1>
                <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                    Deploy several resources of {{ $environment->name }} together, in a fixed order.
                </p>
            </div>
            <div class="flex w-fit shrink-0 items-center gap-2">
                <a href="{{ route('project.resource.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}"
                    {{ wireNavigate() }} class="button whitespace-nowrap">
                    <x-reicon name="layers" class="size-3.5" />
                    Resources
                </a>
                <a href="{{ route('deployment-freezes.index') }}" {{ wireNavigate() }} class="button whitespace-nowrap">
                    <x-reicon name="calendar" class="size-3.5" />
                    Deployment freezes
                </a>
                <a href="{{ route('event-webhooks.index') }}" {{ wireNavigate() }} class="button whitespace-nowrap">
                    <x-reicon name="notifications" class="size-3.5" />
                    Event webhooks
                </a>
            </div>
        </header>

        <div class="flex flex-col gap-6">
            @foreach ($freezes as $freeze)
                <x-callout :type="$freeze->isActive() ? 'danger' : 'warning'" :title="$freeze->isActive() ? 'Deployment freeze active' : 'Deployment freeze planned'">
                    <p>{{ $freeze->describe() }}@unless ($freeze->isActive()) Starts {{ $freeze->starts_at->diffForHumans() }}.@endunless</p>
                </x-callout>
            @endforeach
            <x-application.settings-section title="Release groups" flush>
                @if ($groups->isEmpty())
                    <div class="p-6">
                        <x-empty size="sm" title="No release groups yet"
                            description="A release group lists the applications that are released together, their order and what happens when one fails."
                            icon-name="layers" />
                    </div>
                @else
                    <div class="data-table">
                        <div class="data-table-header release-groups-table-grid">
                            <span>Name</span>
                            <span>Resources</span>
                            <span>Last release</span>
                            <span>Started</span>
                        </div>
                        @foreach ($groups as $group)
                            @php $latest = $group->releases->first(); @endphp
                            <a wire:key="release-group-{{ $group->uuid }}" {{ wireNavigate() }}
                                href="{{ route('project.release-group.show', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'release_group_uuid' => $group->uuid]) }}"
                                class="data-table-row release-groups-table-grid border-b border-neutral-200 last:border-b-0 hover:bg-neutral-50 dark:border-white/[0.08] dark:hover:bg-white/[0.03]">
                                <span class="min-w-0">
                                    <span class="block truncate text-[13px] font-medium">{{ $group->name }}</span>
                                    @if (! $group->is_enabled)
                                        <span class="text-[12px] text-neutral-500 dark:text-fg-dim">Disabled</span>
                                    @elseif ($group->description)
                                        <span class="block truncate text-[12px] text-neutral-500 dark:text-fg-dim">{{ $group->description }}</span>
                                    @endif
                                </span>
                                <span class="text-[12px] text-neutral-600 dark:text-fg-dim">{{ $group->members_count }}</span>
                                <span>
                                    @if ($latest)
                                        <x-status-badge :status="$latest->state->label()" :type="$latest->state->badgeType()" />
                                    @else
                                        <span class="text-[12px] text-neutral-500 dark:text-fg-dim">Never released</span>
                                    @endif
                                </span>
                                <span class="text-[12px] text-neutral-600 dark:text-fg-dim">{{ $latest?->created_at?->diffForHumans() }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </x-application.settings-section>

            @if ($capacities->isNotEmpty())
                <x-application.settings-section title="Build slots" flush
                    helper="Deployment slots of the servers this environment deploys to. A reserved slot belongs to a deployment that waits for a queue worker.">
                    <div class="data-table overflow-x-auto">
                        <div class="data-table-header build-capacity-table-grid">
                            <span>Server</span>
                            <span>Slots</span>
                            <span>Running</span>
                            <span>Reserved</span>
                            <span>Queued</span>
                            <span>Oldest in queue</span>
                        </div>
                        @foreach ($capacities as $capacity)
                            <div wire:key="capacity-{{ $capacity->serverUuid }}"
                                class="data-table-row build-capacity-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]">
                                <span class="min-w-0 truncate text-[13px] font-medium">{{ $capacity->serverName }}</span>
                                <span class="text-[12px]">{{ $capacity->concurrentBuilds }}</span>
                                <span class="text-[12px]">{{ $capacity->running }}</span>
                                <span class="text-[12px]">{{ $capacity->reserved }}</span>
                                <span class="text-[12px]">{{ $capacity->queued }} / {{ $capacity->queueLimit }}</span>
                                <span class="text-[12px] text-neutral-600 dark:text-fg-dim">{{ $capacity->oldestQueuedSeconds === null ? 'Empty' : \Carbon\CarbonInterval::seconds($capacity->oldestQueuedSeconds)->cascade()->forHumans(['short' => true]) }}</span>
                            </div>
                        @endforeach
                    </div>
                </x-application.settings-section>
            @endif

            @can('create', \App\Models\ReleaseGroup::class)
                <form wire:submit="create">
                    <x-application.settings-section title="New release group">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-forms.input id="name" label="Name" required placeholder="Production web stack" />
                            <x-forms.input id="description" label="Description" />
                        </div>
                        <div class="mt-4">
                            <x-forms.button type="submit">
                                <x-reicon name="plus" class="size-3.5" />
                                Create release group
                            </x-forms.button>
                        </div>
                    </x-application.settings-section>
                </form>
            @endcan
        </div>
    </div>
</div>
