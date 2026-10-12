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
            </div>
        </header>

        <div class="flex flex-col gap-6">
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
