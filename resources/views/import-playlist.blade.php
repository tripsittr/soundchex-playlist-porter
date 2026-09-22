{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Import Playlist (S-311/S-312): connect a service or upload a file, then the
     result of the port with any unmatched tracks to resolve. Built from standard
     Filament sections. --}}
<x-filament-panels::page>
    @php($import = $this->currentImport())

    {{-- Connect a streaming service --}}
    <x-filament::section>
        <x-slot name="heading">Connect a music service</x-slot>
        <x-slot name="description">
            Import straight from your account — no file needed. Spotify has no playlist file to
            export, so connecting is the way to bring a Spotify playlist in.
        </x-slot>

        <div class="space-y-4">
            @foreach ($this->sources() as $source)
                <div class="flex flex-col gap-4 rounded-lg border border-gray-200 p-4 dark:border-white/10 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-3">
                        <span class="text-sm font-semibold text-gray-950 dark:text-white">{{ $source['name'] }}</span>
                        @if (! $source['configured'])
                            <x-filament::badge color="gray">Not set up</x-filament::badge>
                        @elseif ($source['connected'])
                            <x-filament::badge color="success">Connected</x-filament::badge>
                        @else
                            <x-filament::badge color="warning">Not connected</x-filament::badge>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        @if ($source['configured'] && $source['connected'])
                            <x-filament::button size="sm" wire:click="loadPlaylists('{{ $source['key'] }}')">
                                Load my playlists
                            </x-filament::button>
                            <x-filament::button size="sm" color="gray" wire:click="disconnectSource('{{ $source['key'] }}')">
                                Disconnect
                            </x-filament::button>
                        @elseif ($source['configured'])
                            {{-- A real link (not a scripted popup) so the browser
                                 does not block the new tab. --}}
                            <x-filament::button
                                tag="a"
                                size="sm"
                                target="_blank"
                                href="{{ route('playlist-porter.oauth.start', ['source' => $source['key']]) }}"
                            >
                                Connect {{ $source['name'] }}
                            </x-filament::button>
                        @endif
                    </div>
                </div>

                {{-- The redirect URI is shown whether or not credentials are saved:
                     a mismatch here is the usual cause of Spotify's
                     "redirect_uri: Not matching configuration" (S-322), and it
                     needs to be re-checkable after setup. --}}
                @if ($source['key'] === 'spotify')
                    <div class="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-white/10">
                        <div class="space-y-1.5">
                            <label class="text-sm font-medium text-gray-950 dark:text-white">Redirect URI to add in Spotify</label>
                            <code class="block w-full overflow-x-auto rounded-md bg-gray-100 px-3 py-2 text-xs text-gray-800 dark:bg-white/10 dark:text-gray-200">{{ $this->spotifyRedirectUri() }}</code>
                            <p class="text-xs text-gray-600 dark:text-gray-400">
                                Paste this into your Spotify app's <span class="font-medium">Redirect URIs</span> exactly as shown, then save it there.
                                Spotify rejects any difference, including the port and a trailing slash.
                            </p>
                        </div>

                        <details class="text-sm">
                            <summary class="cursor-pointer text-gray-600 hover:underline dark:text-gray-400">
                                This server answers on more than one address — change the one used here
                            </summary>
                            <form wire:submit="saveOauthBaseUrl" class="mt-3 space-y-2">
                                <p class="text-xs text-gray-600 dark:text-gray-400">
                                    The address Spotify should send you back to. It must be one this browser can reach.
                                    Leave it empty to use the server's own address ({{ $this->oauthBaseUrlDefault() }}).
                                </p>
                                <div class="flex flex-wrap items-start gap-2">
                                    <x-filament::input.wrapper class="grow">
                                        <x-filament::input type="url" wire:model="oauthBaseUrl" placeholder="{{ $this->oauthBaseUrlDefault() }}" />
                                    </x-filament::input.wrapper>
                                    <x-filament::button type="submit" size="sm" color="gray">Save</x-filament::button>
                                </div>
                                @error('oauthBaseUrl') <p class="text-xs text-danger-600">{{ $message }}</p> @enderror
                            </form>
                        </details>
                    </div>
                @endif

                {{-- First-time setup for Spotify: the app credentials the operator
                     registers once at developer.spotify.com. --}}
                @if ($source['key'] === 'spotify' && ! $source['configured'])
                    <div class="space-y-4 rounded-lg border border-dashed border-gray-300 p-4 dark:border-white/15">
                        <div class="space-y-1">
                            <p class="text-sm font-medium text-gray-950 dark:text-white">Set up Spotify (one time)</p>
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                Create an app at
                                <a href="https://developer.spotify.com/dashboard" target="_blank" class="text-primary-600 hover:underline">developer.spotify.com/dashboard</a>,
                                add the redirect URI below to it, then paste its Client ID and Client Secret here.
                            </p>
                        </div>

                        <form wire:submit="saveSpotifyCredentials" class="space-y-3">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="space-y-1.5">
                                    <label class="text-sm font-medium text-gray-950 dark:text-white">Client ID</label>
                                    <x-filament::input.wrapper>
                                        <x-filament::input type="text" wire:model="spotifyClientId" placeholder="From your Spotify app" />
                                    </x-filament::input.wrapper>
                                    @error('spotifyClientId') <p class="text-xs text-danger-600">{{ $message }}</p> @enderror
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-sm font-medium text-gray-950 dark:text-white">Client Secret</label>
                                    <x-filament::input.wrapper>
                                        <x-filament::input type="password" wire:model="spotifyClientSecret" placeholder="From your Spotify app" />
                                    </x-filament::input.wrapper>
                                    @error('spotifyClientSecret') <p class="text-xs text-danger-600">{{ $message }}</p> @enderror
                                </div>
                            </div>
                            <x-filament::button type="submit" size="sm">Save Spotify credentials</x-filament::button>
                        </form>
                    </div>
                @endif
            @endforeach

            {{-- The connected service's playlists to pick from --}}
            @if (! empty($this->servicePlaylists))
                <div class="rounded-lg border border-gray-200 dark:border-white/10">
                    <ul class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($this->servicePlaylists as $pl)
                            <li class="flex items-center justify-between gap-3 px-4 py-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $pl['name'] }}</p>
                                    @if (! empty($pl['track_count']))
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $pl['track_count'] }} tracks</p>
                                    @endif
                                    {{-- A development-mode Spotify app may only read
                                         playlists the connected account created, so
                                         say so here rather than after a failed click. --}}
                                    @if (isset($pl['readable']) && ! $pl['readable'])
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            Made by someone else — Spotify will not let this app read it.
                                        </p>
                                    @endif
                                </div>
                                @if (isset($pl['readable']) && ! $pl['readable'])
                                    <x-filament::button size="sm" color="gray" disabled>
                                        Import
                                    </x-filament::button>
                                @else
                                    <x-filament::button
                                        size="sm"
                                        color="gray"
                                        wire:click="importFromSource('spotify', '{{ $pl['id'] }}')"
                                        wire:loading.attr="disabled"
                                    >
                                        Import
                                    </x-filament::button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </x-filament::section>

    {{-- Import from a file --}}
    <x-filament::section>
        <x-slot name="heading">Import from a file</x-slot>
        <x-slot name="description">
            Upload an M3U, CSV (including Spotify/Exportify exports), or XSPF file. Each track is
            matched to your library and saved as a playlist.
        </x-slot>

        <form wire:submit="import" class="space-y-5">
            <div class="space-y-1.5">
                <label class="text-sm font-medium text-gray-950 dark:text-white">Playlist file</label>
                <input
                    type="file"
                    wire:model="file"
                    accept=".m3u,.m3u8,.csv,.xspf,text/plain,text/csv,application/xml"
                    class="block w-full text-sm text-gray-700 file:mr-4 file:rounded-lg file:border-0 file:bg-primary-600 file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-white hover:file:bg-primary-500 dark:text-gray-300"
                />
                @error('file') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                <p wire:loading wire:target="file" class="text-sm text-gray-500">Uploading…</p>
            </div>

            <div class="space-y-1.5">
                <label class="text-sm font-medium text-gray-950 dark:text-white">
                    Playlist name <span class="font-normal text-gray-400">(optional)</span>
                </label>
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="name" placeholder="Taken from the file if left blank" />
                </x-filament::input.wrapper>
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
                Matched {{ $import->matched_tracks }} of {{ $import->total_tracks }} tracks · from {{ strtoupper($import->source_format) }}
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
                            <li class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between" wire:key="unmatched-{{ $index }}">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-gray-950 dark:text-white">
                                        {{ $track['title'] ?? $track['source_label'] ?? 'Unknown track' }}
                                    </p>
                                    @if (! empty($track['artist']) || ! empty($track['album']))
                                        <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                                            {{ collect([$track['artist'] ?? null, $track['album'] ?? null])->filter()->implode(' · ') }}
                                        </p>
                                    @endif
                                </div>
                                <div class="flex flex-col items-stretch gap-2 sm:items-end">
                                    {{-- The matcher's own suggestions first: picking
                                         one is a click, not a database id. --}}
                                    @foreach ($track['candidates'] ?? [] as $candidate)
                                        <x-filament::button
                                            size="sm"
                                            color="gray"
                                            wire:click="acceptCandidate({{ $index }}, {{ $candidate['media_item_id'] }})"
                                        >
                                            Use “{{ Str::limit($candidate['title'], 30) }}”
                                            @if (! empty($candidate['album']))
                                                <span class="font-normal opacity-70">· {{ Str::limit($candidate['album'], 20) }}</span>
                                            @endif
                                        </x-filament::button>
                                    @endforeach

                                    <div class="flex items-center gap-2">
                                        <x-filament::input.wrapper class="w-40">
                                            <x-filament::input type="text" wire:model="resolveTo.{{ $index }}" placeholder="Library track id" />
                                        </x-filament::input.wrapper>
                                        <x-filament::button size="sm" color="gray" wire:click="resolve({{ $index }})">Match</x-filament::button>
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
