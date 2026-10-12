<div>
    <x-slot:title>Event webhooks | Coolify</x-slot>
    <div class="w-full">
        <header class="mb-5">
            <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">Event webhooks</h1>
            <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                Coolify sends release, deployment and freeze events of this team to these HTTPS endpoints. Each request is signed:
                <span class="font-mono">Coolify-Signature: t=&lt;time&gt;,v1=&lt;HMAC-SHA256 of "time.body"&gt;</span>. Failed deliveries are retried for about eight hours. The same events are available at <span class="font-mono">GET /api/v1/events</span>.
            </p>
        </header>

        <div class="flex flex-col gap-6">
            @if ($createdSecret)
                <x-callout type="warning" title="Copy the signing secret now">
                    <p>It is shown only once: <span class="font-mono break-all">{{ $createdSecret }}</span></p>
                </x-callout>
            @endif

            <x-application.settings-section title="Endpoints" flush>
                @if ($subscriptions->isEmpty())
                    <div class="p-6"><x-empty size="sm" title="No event webhooks" description="Add an endpoint to receive events as they happen." icon-name="notifications" /></div>
                @else
                    @foreach ($subscriptions as $subscription)
                        <div wire:key="subscription-{{ $subscription->uuid }}" class="flex flex-col gap-2 border-b border-neutral-200 p-4 last:border-b-0 dark:border-white/[0.08]">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <span class="text-[13px] font-medium">{{ $subscription->name }}</span>
                                    <span class="ml-2 text-[12px] text-neutral-500 dark:text-fg-dim">{{ parse_url((string) $subscription->url, PHP_URL_HOST) }}, {{ implode(', ', $subscription->event_types) }}</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <x-status-badge :status="$subscription->is_enabled ? 'Enabled' : 'Disabled'" :type="$subscription->is_enabled ? 'success' : 'error'" />
                                    @if ($isAdmin)
                                        <x-forms.button wire:click="test('{{ $subscription->uuid }}')">Send test</x-forms.button>
                                        <x-modal-confirmation title="Delete this event webhook?" isErrorButton buttonTitle="Delete"
                                            submitAction="delete('{{ $subscription->uuid }}')" :confirmWithText="false" :confirmWithPassword="false"
                                            step2ButtonText="Delete" :actions="['Pending deliveries to this endpoint are dropped.']" />
                                    @endif
                                </div>
                            </div>
                            @if ($subscription->disabled_reason)
                                <p class="text-[12px] text-red-600 dark:text-red-400">{{ $subscription->disabled_reason }} Send a test to enable it again.</p>
                            @endif
                            @if ($subscription->deliveries->isNotEmpty())
                                <ul class="flex flex-col gap-1 text-[12px] text-neutral-600 dark:text-fg-dim">
                                    @foreach ($subscription->deliveries as $delivery)
                                        <li>{{ $delivery->created_at->diffForHumans() }}: {{ $delivery->event?->type }}, {{ $delivery->state }}@if ($delivery->response_status) (HTTP {{ $delivery->response_status }})@endif @if ($delivery->last_error && $delivery->state !== 'delivered') {{ $delivery->last_error }}@endif</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach
                @endif
            </x-application.settings-section>

            @if ($isAdmin)
                <form wire:submit="create">
                    <x-application.settings-section title="New event webhook">
                        <div class="grid gap-4 lg:grid-cols-2">
                            <x-forms.input id="name" label="Name" required placeholder="Release dashboard" />
                            <x-forms.input id="url" label="HTTPS URL" required placeholder="https://example.com/hooks/coolify" />
                            <x-forms.listbox multiple id="eventTypes" label="Events" :options="$typeOptions" />
                        </div>
                        <div class="mt-4">
                            <x-forms.button type="submit">
                                <x-reicon name="plus" class="size-3.5" />
                                Add event webhook
                            </x-forms.button>
                        </div>
                    </x-application.settings-section>
                </form>
            @endif
        </div>
    </div>
</div>
