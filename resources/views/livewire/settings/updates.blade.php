<div>
    <x-slot:title>
        Update Settings | Coolify
    </x-slot>

    <x-settings.layout>
        <form wire:submit="submit" class="application-settings-form flex min-w-0 flex-col gap-6">
            {{-- Exclude is_auto_update_enabled (instantSave) so the bar does not flash. --}}
            <x-unsaved-bar action="submit" targets="update_check_frequency,auto_update_frequency" />

            @if ($isCustomDistribution)
                @php
                    $identity = $distributionState->identity;
                    $candidate = $distributionState->candidate;
                    $check = $distributionState->check;
                    $upstream = $check?->manifest?->upstream;
                    $commitUrl = fn (?string $commit) => $commit && $identity->sourceRepository ? rtrim($identity->sourceRepository, '/').'/commit/'.$commit : null;
                @endphp

                <x-application.settings-section title="Running version"
                    helper="What this Coolify instance runs, read from the build inside the image and from the running container. The .env file is not used as proof.">
                    @if (! $identity->isValid())
                        <x-callout type="danger" title="Build identity invalid">
                            <p>{{ $identity->invalidReason }} Updates stay blocked until the image is replaced.</p>
                        </x-callout>
                    @else
                        <dl class="grid grid-cols-[minmax(0,10rem)_minmax(0,1fr)] gap-x-4 gap-y-2 text-[13px]">
                            <dt class="text-neutral-500 dark:text-fg-dim">Distribution</dt>
                            <dd class="min-w-0 truncate font-mono">{{ $identity->distribution }}</dd>
                            <dt class="text-neutral-500 dark:text-fg-dim">Release</dt>
                            <dd class="min-w-0 truncate">{{ $distributionState->runningLabel() }}
                                <span class="text-neutral-500 dark:text-fg-dim">(build {{ $identity->buildNumber }})</span></dd>
                            <dt class="text-neutral-500 dark:text-fg-dim">Coolify base</dt>
                            <dd class="font-mono">{{ $identity->baseVersion }}</dd>
                            <dt class="text-neutral-500 dark:text-fg-dim">Commit</dt>
                            <dd class="min-w-0 truncate font-mono">
                                @if ($commitUrl($identity->sourceCommit))
                                    <a class="underline underline-offset-2" href="{{ $commitUrl($identity->sourceCommit) }}" target="_blank" rel="noopener noreferrer">{{ $identity->sourceCommit }}</a>
                                @else
                                    {{ $identity->sourceCommit }}
                                @endif
                            </dd>
                            <dt class="text-neutral-500 dark:text-fg-dim">Image digest</dt>
                            <dd class="flex min-w-0 flex-wrap items-center gap-2">
                                @if ($runningDigest)
                                    <span class="min-w-0 truncate font-mono">{{ $runningDigest }}</span>
                                    @if ($distributionState->running && hash_equals($distributionState->running->digest, $runningDigest))
                                        <x-status-badge status="Matches release" type="success" />
                                    @elseif ($distributionState->running)
                                        <x-status-badge status="Differs from release" type="error" />
                                    @else
                                        <x-status-badge status="Not in manifest" type="warning" />
                                    @endif
                                @elseif ($runningImageKnown)
                                    <span class="text-neutral-500 dark:text-fg-dim">The running image was not pulled from {{ $identity->imageRepository }}.</span>
                                    <x-status-badge status="Unverified" type="warning" />
                                @else
                                    <span class="text-neutral-500 dark:text-fg-dim">The running container could not be inspected.</span>
                                    <x-status-badge status="Unknown" type="neutral" />
                                @endif
                            </dd>
                            <dt class="text-neutral-500 dark:text-fg-dim">Image</dt>
                            <dd class="min-w-0 truncate font-mono">{{ $identity->imageRepository }}</dd>
                            <dt class="text-neutral-500 dark:text-fg-dim">Signed images</dt>
                            <dd>{{ $identity->requiresSignature() ? 'Required' : 'Not required' }}</dd>
                        </dl>
                        @if ($environmentVersionMismatch)
                            <x-callout type="warning" title="Version mismatch" class="mt-4">
                                <p>COOLIFY_VERSION in /data/coolify/source/.env says {{ config('constants.coolify.configured_version') }}, but the running build is based on {{ $identity->baseVersion }}. The official upgrade script may have run. The image pin in docker-compose.custom.yml kept this distribution.</p>
                            </x-callout>
                        @endif
                        @if ($distributionState->manifestDisagreesWithBuild)
                            <x-callout type="danger" title="Manifest mismatch" class="mt-4">
                                <p>The distribution manifest lists a different commit for build {{ $identity->buildNumber }}. Updates stay blocked. Check the release workflow and the manifest history.</p>
                            </x-callout>
                        @endif
                    @endif
                </x-application.settings-section>

                <x-application.settings-section title="Distribution updates"
                    helper="Releases of this distribution. Only releases that passed CI and are published on the selected channel can be installed.">
                    <x-slot:actions>
                        <x-forms.button type="button" wire:click="checkManually">
                            <x-reicon name="refresh" class="size-3.5" />
                            Check now
                        </x-forms.button>
                    </x-slot:actions>
                    <div class="flex flex-col gap-4">
                        <div class="max-w-md">
                            <x-forms.listbox id="distribution_channel" label="Channel" onChange="instantSave"
                                helper="Stable is the default for production. Staging and development builds are for test installations."
                                :options="[
                                    ['value' => 'stable', 'label' => 'Stable'],
                                    ['value' => 'staging', 'label' => 'Staging'],
                                    ['value' => 'development', 'label' => 'Development'],
                                ]" />
                        </div>

                        @if ($check?->error)
                            <x-callout :type="$check->stale ? 'warning' : 'danger'" title="Update check failed">
                                <p>{{ $check->error }}@if ($check->stale) The last known manifest is shown.@endif</p>
                            </x-callout>
                        @elseif ($check === null)
                            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">No update check has run yet. Use “Check now”.</p>
                        @endif

                        @if ($candidate)
                            <dl class="grid grid-cols-[minmax(0,10rem)_minmax(0,1fr)] gap-x-4 gap-y-2 text-[13px]">
                                <dt class="text-neutral-500 dark:text-fg-dim">Channel release</dt>
                                <dd class="min-w-0 truncate">{{ $candidate->version }} <span class="text-neutral-500 dark:text-fg-dim">(build {{ $candidate->build }})</span></dd>
                                <dt class="text-neutral-500 dark:text-fg-dim">Coolify base</dt>
                                <dd class="font-mono">{{ $candidate->baseVersion }}</dd>
                                <dt class="text-neutral-500 dark:text-fg-dim">Commit</dt>
                                <dd class="min-w-0 truncate font-mono">{{ $candidate->commit }}</dd>
                                <dt class="text-neutral-500 dark:text-fg-dim">Digest</dt>
                                <dd class="min-w-0 truncate font-mono">{{ $candidate->digest }}</dd>
                                <dt class="text-neutral-500 dark:text-fg-dim">CI verification</dt>
                                <dd class="flex flex-wrap items-center gap-2">
                                    <x-status-badge :status="str($candidate->verificationStatus)->headline()" :type="$candidate->isVerified() ? 'success' : ($candidate->verificationStatus === 'failed' ? 'error' : 'warning')" />
                                    @if ($candidate->verificationUrl)
                                        <a class="underline underline-offset-2" href="{{ $candidate->verificationUrl }}" target="_blank" rel="noopener noreferrer">Workflow run</a>
                                    @endif
                                </dd>
                                <dt class="text-neutral-500 dark:text-fg-dim">Database migrations</dt>
                                <dd>{{ $candidate->latestMigration ?? 'Not declared' }}</dd>
                                @if ($candidate->publishedAt)
                                    <dt class="text-neutral-500 dark:text-fg-dim">Published</dt>
                                    <dd>{{ $candidate->publishedAt }}</dd>
                                @endif
                                @if ($candidate->summary)
                                    <dt class="text-neutral-500 dark:text-fg-dim">Changes</dt>
                                    <dd class="min-w-0">{{ $candidate->summary }}
                                        @if ($candidate->notesUrl)
                                            <a class="underline underline-offset-2" href="{{ $candidate->notesUrl }}" target="_blank" rel="noopener noreferrer">Release notes</a>
                                        @endif
                                    </dd>
                                @endif
                            </dl>
                        @elseif ($check?->manifest)
                            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">No release is published on the {{ $distributionState->channel }} channel.</p>
                        @endif

                        @if ($distributionState->blockedReason)
                            <x-callout type="warning" title="Not installable">
                                <p>{{ $distributionState->blockedReason }}</p>
                            </x-callout>
                        @endif

                        <livewire:upgrade :full-button="true" key="settings-upgrade" />
                    </div>
                </x-application.settings-section>

                <x-application.settings-section title="Official Coolify"
                    helper="Information about the upstream project. Official releases are never installed on this instance; they reach it through a new distribution release.">
                    <dl class="grid grid-cols-[minmax(0,10rem)_minmax(0,1fr)] gap-x-4 gap-y-2 text-[13px]">
                        <dt class="text-neutral-500 dark:text-fg-dim">Latest official</dt>
                        <dd class="font-mono">{{ $upstream?->latestRelease ?? $officialLatestVersion ?? 'Unknown' }}</dd>
                        <dt class="text-neutral-500 dark:text-fg-dim">This build is based on</dt>
                        <dd class="flex flex-wrap items-center gap-2">
                            <span class="font-mono">{{ $identity->baseVersion ?? 'Unknown' }}</span>
                            @php
                                $behind = $upstream?->isBehind($identity->baseVersion)
                                    ?? (($officialLatestVersion && $identity->baseVersion) ? version_compare($identity->baseVersion, $officialLatestVersion, '<') : null);
                            @endphp
                            @if ($behind === true)
                                <x-status-badge status="Behind upstream" type="warning" />
                            @elseif ($behind === false)
                                <x-status-badge status="Up to date" type="success" />
                            @endif
                        </dd>
                        @if ($upstream)
                            <dt class="text-neutral-500 dark:text-fg-dim">Upstream sync</dt>
                            <dd class="flex min-w-0 flex-wrap items-center gap-2">
                                <x-status-badge :status="str($upstream->syncState)->headline()" :type="match ($upstream->syncState) { 'clean', 'merged' => 'success', 'conflicts' => 'error', default => 'warning' }" />
                                @if ($upstream->commitsBehind !== null)
                                    <span>{{ $upstream->commitsBehind }} upstream commit(s) not merged</span>
                                @endif
                                @if ($upstream->syncPullRequestUrl)
                                    <a class="underline underline-offset-2" href="{{ $upstream->syncPullRequestUrl }}" target="_blank" rel="noopener noreferrer">Sync pull request</a>
                                @endif
                            </dd>
                            @if ($upstream->conflictingFiles !== [])
                                <dt class="text-neutral-500 dark:text-fg-dim">Conflicts</dt>
                                <dd class="min-w-0 font-mono text-[12px]">{{ implode(', ', array_slice($upstream->conflictingFiles, 0, 12)) }}@if (count($upstream->conflictingFiles) > 12) and {{ count($upstream->conflictingFiles) - 12 }} more @endif</dd>
                            @endif
                            <dt class="text-neutral-500 dark:text-fg-dim">Last synced</dt>
                            <dd>{{ $upstream->lastSyncedAt ?? 'Unknown' }}</dd>
                            @php
                                $pendingAdvisories = $upstream->advisoriesPendingFor($identity->baseVersion);
                            @endphp
                            <dt class="text-neutral-500 dark:text-fg-dim">Security advisories</dt>
                            <dd class="min-w-0">
                                @if ($pendingAdvisories === [])
                                    No published advisory is fixed in a newer official release.
                                @else
                                    <ul class="flex flex-col gap-1">
                                        @foreach ($pendingAdvisories as $advisory)
                                            <li class="flex flex-wrap items-center gap-2">
                                                <x-status-badge :status="str($advisory['severity'])->headline()" :type="in_array($advisory['severity'], ['high', 'critical'], true) ? 'error' : 'warning'" />
                                                @if ($advisory['url'])
                                                    <a class="underline underline-offset-2" href="{{ $advisory['url'] }}" target="_blank" rel="noopener noreferrer">{{ $advisory['id'] }}</a>
                                                @else
                                                    <span>{{ $advisory['id'] }}</span>
                                                @endif
                                                <span class="text-neutral-500 dark:text-fg-dim">fixed in {{ $advisory['first_patched_version'] }}: {{ $advisory['summary'] }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </dd>
                        @else
                            <dt class="text-neutral-500 dark:text-fg-dim">Upstream sync</dt>
                            <dd class="text-neutral-500 dark:text-fg-dim">The distribution manifest has no upstream status yet.</dd>
                        @endif
                    </dl>
                </x-application.settings-section>

                @if ($rollbackCandidate)
                    <x-application.settings-section title="Previous version"
                        helper="Restoring the previous image is only offered when no database migration ran since it was replaced.">
                        <div class="flex flex-col gap-3 text-[13px]">
                            @php
                                $rollbackLabel = $rollbackCandidate->release?->version ?? $rollbackCandidate->sourceUpgrade->from_version;
                            @endphp
                            <p>{{ $rollbackLabel }}
                                @if ($rollbackCandidate->release)
                                    <span class="text-neutral-500 dark:text-fg-dim">({{ $rollbackLabel === "build {$rollbackCandidate->release->build}" ? '' : "build {$rollbackCandidate->release->build}, " }}{{ $rollbackCandidate->release->shortDigest() }})</span>
                                @endif
                            </p>
                            <x-callout :type="$rollbackCandidate->imageRollbackSafe ? 'info' : 'warning'" :title="$rollbackCandidate->imageRollbackSafe ? 'Image rollback possible' : 'Image rollback not safe'">
                                <p>{{ $rollbackCandidate->explanation }}</p>
                            </x-callout>
                            @if ($rollbackCandidate->imageRollbackSafe)
                                <div>
                                    <x-modal-confirmation title="Restore the previous version?" buttonTitle="Restore previous version"
                                        submitAction="rollback" :confirmWithText="false" :confirmWithPassword="false"
                                        step2ButtonText="Restore previous version"
                                        :actions="['Coolify restarts with the previous image. Running deployments fail.', 'The database is not changed.']" />
                                </div>
                            @endif
                        </div>
                    </x-application.settings-section>
                @endif

                <x-application.settings-section title="Update history" flush>
                    @if ($upgradeHistory->isEmpty())
                        <div class="p-6">
                            <x-empty size="sm" title="No distribution updates yet" description="Updates started from this page are recorded here with their checks and result." icon-name="refresh" />
                        </div>
                    @else
                        <div class="data-table">
                            <div class="data-table-header distribution-upgrades-table-grid">
                                <span>Started</span>
                                <span>Change</span>
                                <span>Trigger</span>
                                <span>Result</span>
                                <span>Details</span>
                            </div>
                            @foreach ($upgradeHistory as $upgrade)
                                <div wire:key="distribution-upgrade-{{ $upgrade->uuid }}"
                                    class="data-table-row distribution-upgrades-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]">
                                    <span class="text-[12px] text-neutral-600 dark:text-fg-dim">{{ ($upgrade->started_at ?? $upgrade->created_at)?->toDayDateTimeString() }}</span>
                                    <span class="min-w-0 truncate text-[12px]">{{ $upgrade->from_version ?? 'unknown' }} → {{ $upgrade->to_version }}</span>
                                    <span class="text-[12px] text-neutral-600 dark:text-fg-dim">{{ str($upgrade->trigger)->headline() }}@if ($upgrade->requestedBy) ({{ $upgrade->requestedBy->name }})@endif</span>
                                    <span><x-status-badge :status="$upgrade->state->label()" :type="$upgrade->state->badgeType()" /></span>
                                    <span class="min-w-0 text-[12px] text-neutral-600 dark:text-fg-dim">{{ \Illuminate\Support\Str::limit((string) $upgrade->message, 160) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-application.settings-section>
            @else
                <x-application.settings-section title="Update Coolify"
                    helper="Install the latest Coolify version manually when an update is available.">
                    <livewire:upgrade :full-button="true" key="settings-upgrade" />
                </x-application.settings-section>
            @endif

            <x-application.settings-section title="Update checks">
                @unless ($isCustomDistribution)
                    <x-slot:actions>
                        <x-forms.button type="button" wire:click="checkManually">
                            <x-reicon name="refresh" class="size-3.5" />
                            Check now
                        </x-forms.button>
                    </x-slot:actions>
                @endunless
                <x-forms.input required id="update_check_frequency" label="Check frequency"
                    placeholder="0 * * * *"
                    helper="A cron expression or preset such as hourly, daily, weekly, monthly, or yearly." />
            </x-application.settings-section>

            <x-application.settings-section title="Automatic updates">
                @if ($isCustomDistribution)
                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.listbox id="is_distribution_auto_update_enabled" label="Install distribution releases automatically"
                            onChange="instantSave"
                            helper="Off by default. When on, a release is only installed if every update check passes; nothing is acknowledged automatically."
                            :options="[
                                ['value' => true, 'label' => 'Enabled'],
                                ['value' => false, 'label' => 'Disabled'],
                            ]" />
                        @if ($is_distribution_auto_update_enabled)
                            <x-forms.input required id="auto_update_frequency" label="Update frequency"
                                placeholder="0 0 * * *"
                                helper="Cron expression or preset for installing updates." />
                        @else
                            <x-forms.input label="Update frequency" disabled placeholder="Disabled" />
                        @endif
                        <x-forms.input type="number" min="1" max="720" id="distribution_backup_max_age_hours"
                            label="Maximum backup age (hours)"
                            helper="Updates with database migrations need a successful backup of the Coolify database that is not older than this." />
                    </div>
                @else
                    <div class="grid gap-4 lg:grid-cols-2">
                        @if (!is_null(config('constants.coolify.autoupdate', null)))
                            <x-forms.listbox disabled id="is_auto_update_enabled" label="Automatic updates"
                                helper="Controlled by the AUTOUPDATE environment variable." :options="[
                                    ['value' => true, 'label' => 'Enabled'],
                                    ['value' => false, 'label' => 'Disabled'],
                                ]" />
                        @else
                            <x-forms.listbox id="is_auto_update_enabled" label="Automatic updates"
                                onChange="instantSave" :options="[
                                    ['value' => true, 'label' => 'Enabled'],
                                    ['value' => false, 'label' => 'Disabled'],
                                ]" />
                        @endif

                        @if (is_null(config('constants.coolify.autoupdate', null)) && $is_auto_update_enabled)
                            <x-forms.input required id="auto_update_frequency" label="Update frequency"
                                placeholder="0 0 * * *"
                                helper="Cron expression or preset for installing updates." />
                        @else
                            <x-forms.input label="Update frequency" disabled placeholder="Disabled" />
                        @endif
                    </div>
                @endif
            </x-application.settings-section>

            <x-application.settings-section title="Image registry">
                <div class="max-w-md">
                    <x-forms.listbox id="docker_registry_url" label="Docker registry" :options="[
                        ['value' => 'docker.io', 'label' => 'Docker Hub'],
                        ['value' => 'ghcr.io', 'label' => 'GitHub Container Registry'],
                    ]"
                        :helper="$isCustomDistribution
                            ? 'Source of the official helper images. The Coolify image itself always comes from the distribution repository.'
                            : 'Switch registries if the current source is rate limited.'" />
                </div>
            </x-application.settings-section>
        </form>
    </x-settings.layout>
</div>
