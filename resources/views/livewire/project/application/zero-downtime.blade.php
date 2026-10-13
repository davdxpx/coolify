<div>
    <x-application.settings-section id="advanced-zero-downtime-section" title="Zero-downtime deployment"
        helper="The new version starts as a second Compose project next to the running one. The proxy serves both while the new one becomes healthy; then the previous containers are stopped. If the new version does not become healthy within five minutes, it is removed and the previous version keeps serving.">
        <div class="flex flex-col gap-3">
            <div class="max-w-md">
                <x-forms.listbox id="isEnabled" label="Deployment" onChange="instantSave"
                    :options="[
                        ['value' => false, 'label' => 'Stop, then start (short interruption)'],
                        ['value' => true, 'label' => 'Start next to the running version (no interruption)'],
                    ]" :disabled="! $canUpdate || (! $report['eligible'] && ! $isEnabled)" />
            </div>
            @if (! $report['eligible'])
                <x-callout type="warning" title="Not possible for this Compose file">
                    <ul class="flex flex-col gap-1">
                        @foreach ($report['blocking'] as $reason)
                            <li>{{ $reason }}</li>
                        @endforeach
                    </ul>
                </x-callout>
            @endif
            @if ($report['warnings'] !== [])
                <ul class="flex flex-col gap-1 text-[12px] text-neutral-600 dark:text-fg-dim">
                    @foreach ($report['warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    </x-application.settings-section>
</div>
