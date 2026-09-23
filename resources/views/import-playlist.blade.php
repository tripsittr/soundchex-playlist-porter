{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Import Playlist (S-311/S-312): connect a service or upload a file, then the
     result of the port with any unmatched tracks to resolve. Built from standard
     Filament sections. --}}
<x-filament-panels::page>
    @php($import = $this->currentImport())

    <x-playlist-porter::styles />

    {{-- Services, as a grid of cards. Setup and the redirect URI live in a
         modal now: they were three stacked panels of forms and explanation on
         a page whose actual job is two clicks (S-347). --}}
    <x-filament::section>
        <x-slot name="heading">
            <span class="inline-flex items-center gap-1.5">
                Music services
                <x-playlist-porter::hint
                    text="Connect an account and import straight from it. Spotify and YouTube Music each need a free developer app registered once — the Set up button shows you what to paste."
                />
            </span>
        </x-slot>

        <div class="scpp-sources">
            @foreach ($this->sources() as $source)
                <div class="scpp-source" wire:key="source-{{ $source['key'] }}">
                    <div>
                        <p class="scpp-source__name">{{ $source['name'] }}</p>
                        @if ($source['connected'])
                            <x-filament::badge color="success" size="sm">Connected</x-filament::badge>
                        @elseif ($source['configured'])
                            <x-filament::badge color="warning" size="sm">Not connected</x-filament::badge>
                        @else
                            <x-filament::badge color="gray" size="sm">Not set up</x-filament::badge>
                        @endif
                    </div>

                    <div class="flex items-center gap-2">
                        @if ($source['connected'])
                            <x-filament::button size="sm" wire:click="loadPlaylists('{{ $source['key'] }}')">
                                Playlists
                            </x-filament::button>
                            <x-filament::dropdown placement="bottom-end">
                                <x-slot name="trigger">
                                    <x-filament::icon-button
                                        icon="heroicon-m-ellipsis-vertical"
                                        label="More"
                                    />
                                </x-slot>
                                <x-filament::dropdown.list>
                                    <x-filament::dropdown.list.item wire:click="refreshPlaylists('{{ $source['key'] }}')">
                                        Refresh playlists
                                    </x-filament::dropdown.list.item>
                                    <x-filament::dropdown.list.item wire:click="openSetup('{{ $source['key'] }}')">
                                        Change credentials
                                    </x-filament::dropdown.list.item>
                                    <x-filament::dropdown.list.item wire:click="disconnectSource('{{ $source['key'] }}')" color="danger">
                                        Disconnect
                                    </x-filament::dropdown.list.item>
                                </x-filament::dropdown.list>
                            </x-filament::dropdown>
                        @elseif ($source['configured'])
                            <x-filament::button
                                tag="a"
                                size="sm"
                                target="_blank"
                                href="{{ route('playlist-porter.oauth.start', ['source' => $source['key']]) }}"
                            >
                                Connect
                            </x-filament::button>
                            <x-filament::button size="sm" color="gray" wire:click="openSetup('{{ $source['key'] }}')">
                                Set up
                            </x-filament::button>
                        @else
                            <x-filament::button size="sm" color="gray" wire:click="openSetup('{{ $source['key'] }}')">
                                Set up
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            @endforeach

            {{-- Named, but honest about why they are not here yet. --}}
            @foreach ($this->plannedSources() as $planned)
                <div class="scpp-source" style="opacity:.6">
                    <div>
                        <p class="scpp-source__name">{{ $planned['name'] }}</p>
                        <span class="scpp-source__soon">Not yet available</span>
                    </div>
                    <x-playlist-porter::hint :text="$planned['note']" />
                </div>
            @endforeach
        </div>

        @if (! empty($this->servicePlaylists))
            <div class="mt-5">
                <p class="mb-2 text-sm font-medium text-gray-950 dark:text-white">
                    Your playlists
                </p>
                <ul class="divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-white/10 dark:border-white/10">
                    @foreach ($this->servicePlaylists as $pl)
                        <li class="scpp-row" style="padding-left:1rem;padding-right:1rem" wire:key="pl-{{ $pl['id'] }}">
                            <div class="scpp-row__text">
                                <p class="scpp-row__title text-gray-950 dark:text-white">{{ $pl['name'] }}</p>
                                @if (! empty($pl['track_count']))
                                    <p class="scpp-row__meta">{{ $pl['track_count'] }} tracks</p>
                                @endif
                                @if (isset($pl['readable']) && ! $pl['readable'])
                                    <p class="scpp-row__meta">Made by someone else — the service will not let this app read it.</p>
                                @endif
                            </div>
                            <div class="scpp-row__actions">
                                @if (isset($pl['readable']) && ! $pl['readable'])
                                    <x-filament::button size="sm" color="gray" disabled>Import</x-filament::button>
                                @else
                                    <x-filament::button
                                        size="sm"
                                        wire:click="importFromSource('{{ $pl['source'] ?? 'spotify' }}', '{{ $pl['id'] }}')"
                                        wire:loading.attr="disabled"
                                    >
                                        Import
                                    </x-filament::button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </x-filament::section>

    {{-- Setup, in a modal. --}}
    @if ($settingUp !== null)
        <x-filament::modal id="scpp-setup" :visible="true" width="lg" wire:close="closeSetup">
            <x-slot name="heading">Set up {{ $this->setupName() }}</x-slot>

            <div class="space-y-4">
                <div class="space-y-1.5">
                    <label class="scpp-label text-gray-950 dark:text-white">
                        Redirect URI
                        <x-playlist-porter::hint text="Paste this into your app's redirect URI list exactly as shown. The service rejects any difference, including the port and a trailing slash." />
                    </label>
                    <code class="block w-full overflow-x-auto rounded-md bg-gray-100 px-3 py-2 text-xs text-gray-800 dark:bg-white/10 dark:text-gray-200">{{ $this->setupRedirectUri() }}</code>
                </div>

                <form wire:submit="saveSetup" class="space-y-3">
                    <div class="scpp-form-grid">
                        <div>
                            <label class="scpp-label text-gray-950 dark:text-white">Client ID</label>
                            <x-filament::input.wrapper>
                                <x-filament::input type="text" wire:model="setupClientId" />
                            </x-filament::input.wrapper>
                            @error('setupClientId') <p class="text-xs text-danger-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="scpp-label text-gray-950 dark:text-white">Client Secret</label>
                            <x-filament::input.wrapper>
                                <x-filament::input type="password" wire:model="setupClientSecret" />
                            </x-filament::input.wrapper>
                            @error('setupClientSecret') <p class="text-xs text-danger-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="flex justify-end gap-2">
                        <x-filament::button type="button" color="gray" wire:click="closeSetup">Cancel</x-filament::button>
                        <x-filament::button type="submit">Save</x-filament::button>
                    </div>
                </form>
            </div>
        </x-filament::modal>
    @endif

    <x-filament::section>
        <x-slot name="heading">
            <span class="inline-flex items-center gap-1.5">
                Import from a file
                <x-playlist-porter::hint
                    text="Upload an M3U, CSV (including Spotify and Exportify exports) or XSPF file. Every track is matched against your library and saved as a SoundChex playlist; anything that cannot be matched is listed for you to resolve."
                />
            </span>
        </x-slot>

        {{-- The file and the name sit side by side: two short fields do not
             need two full-width rows, and the form then reads as one action
             rather than a questionnaire (S-331). --}}
        <form wire:submit="import" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-[2fr,1fr]">
                <div class="space-y-1.5">
                    <label class="flex items-center gap-1.5 text-sm font-medium text-gray-950 dark:text-white">
                        Playlist file
                        <x-playlist-porter::hint text="M3U, M3U8, CSV or XSPF. A Spotify export from Exportify works as-is." />
                    </label>
                    <input
                        type="file"
                        wire:model="file"
                        accept=".m3u,.m3u8,.csv,.xspf,text/plain,text/csv,application/xml"
                        class="block w-full rounded-lg border border-gray-300 text-sm text-gray-700 file:mr-4 file:rounded-l-lg file:border-0 file:bg-primary-600 file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-white hover:file:bg-primary-500 dark:border-white/10 dark:text-gray-300"
                    />
                    @error('file') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                    <p wire:loading wire:target="file" class="text-sm text-gray-500">Uploading…</p>
                </div>

                <div class="space-y-1.5">
                    <label class="flex items-center gap-1.5 text-sm font-medium text-gray-950 dark:text-white">
                        Name
                        <x-playlist-porter::hint text="Leave this blank to use the name stored in the file." />
                    </label>
                    <x-filament::input.wrapper>
                        <x-filament::input type="text" wire:model="name" placeholder="From the file" />
                    </x-filament::input.wrapper>
                </div>
            </div>

            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="import,file">
                <span wire:loading.remove wire:target="import">Import</span>
                <span wire:loading wire:target="import">Matching…</span>
            </x-filament::button>
        </form>
    </x-filament::section>

    {{-- Result --}}
    @if ($import)
        <x-filament::section>
            <x-slot name="heading">{{ $import->name }}</x-slot>
            <x-slot name="description">
                {{-- Badges rather than a run-on sentence: the two facts that
                     matter are how much matched and where it came from (S-333). --}}
                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::badge
                        :color="$import->matched_tracks === $import->total_tracks ? 'success' : 'warning'"
                    >
                        {{ $import->matched_tracks }} of {{ $import->total_tracks }} matched
                    </x-filament::badge>

                    <x-filament::badge color="gray">
                        {{ ucfirst($import->source_format) }}
                    </x-filament::badge>
                </div>
            </x-slot>
            @if ($import->collection_id)
                <x-slot name="afterHeader">
                    <x-filament::button
                        tag="a"
                        size="sm"
                        color="gray"
                        href="{{ url('/admin/collections/'.$import->collection_id.'/edit') }}"
                    >
                        Open playlist
                    </x-filament::button>
                </x-slot>
            @endif

            {{-- Attached, but something disagreed: the library plainly holds the
                 song, while the release or the length is not the one the playlist
                 named. Worth a glance, not worth losing the track over (S-324). --}}
            @php($uncertain = $import->uncertain ?? [])
            @if (count($uncertain) > 0)
                <div class="mb-6 space-y-4">
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ count($uncertain) }} track{{ count($uncertain) === 1 ? ' was' : 's were' }} added from a
                        different release or a different length. Keep the ones that look right.
                    </p>

                    <ul class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($uncertain as $index => $track)
                            <li class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between" wire:key="uncertain-{{ $index }}">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-gray-950 dark:text-white">
                                        {{ $track['title'] ?? 'Unknown track' }}
                                        @if (! empty($track['artist']))
                                            <span class="font-normal text-gray-500 dark:text-gray-400">— {{ $track['artist'] }}</span>
                                        @endif
                                    </p>
                                    <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                                        {{ $track['reason'] ?? '' }}
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <x-filament::button size="sm" color="gray" wire:click="confirmUncertain({{ $index }})">
                                        Keep
                                    </x-filament::button>
                                    <x-filament::button size="sm" color="danger" wire:click="rejectUncertain({{ $index }})">
                                        Remove
                                    </x-filament::button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @php($unmatched = $import->unmatched ?? [])
            @if (count($unmatched) > 0)
                <div class="space-y-4">
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ count($unmatched) }} track{{ count($unmatched) === 1 ? '' : 's' }} weren’t in your library.
                        Match one to a track you own, or leave it out.
                    </p>

                    <ul class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($unmatched as $index => $track)
                            {{-- Layout comes from the plugin's own CSS: the
                                 app's Tailwind build does not scan plugin views,
                                 so utility classes written here are never
                                 compiled and the controls stayed left (S-347). --}}
                            <li class="scpp-row" wire:key="unmatched-{{ $index }}">
                                <div class="scpp-row__text">
                                    <p class="scpp-row__title text-gray-950 dark:text-white">
                                        {{ $track['title'] ?? $track['source_label'] ?? 'Unknown track' }}
                                    </p>
                                    @if (! empty($track['artist']) || ! empty($track['album']))
                                        <p class="scpp-row__meta">
                                            {{ collect([$track['artist'] ?? null, $track['album'] ?? null])->filter()->implode(' · ') }}
                                        </p>
                                    @endif
                                </div>

                                <div class="scpp-row__actions">
                                    @foreach ($track['candidates'] ?? [] as $candidate)
                                        <x-filament::button
                                            size="sm"
                                            color="info"
                                            icon="heroicon-m-check"
                                            wire:click="acceptCandidate({{ $index }}, {{ $candidate['media_item_id'] }})"
                                        >
                                            Use “{{ Str::limit($candidate['title'], 28) }}”
                                        </x-filament::button>
                                    @endforeach

                                    <div class="flex items-center gap-2">
                                        <x-filament::input.wrapper class="w-40">
                                            <x-filament::input
                                                type="text"
                                                wire:model="resolveTo.{{ $index }}"
                                                placeholder="Library track id"
                                            />
                                        </x-filament::input.wrapper>
                                        <x-filament::button
                                            size="sm"
                                            color="info"
                                            wire:click="resolve({{ $index }})"
                                        >
                                            Match
                                        </x-filament::button>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @else
                <p class="text-sm text-success-600">Every track was matched to your library.</p>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
