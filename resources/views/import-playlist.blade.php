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
                        @if (! $source['configured'])
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                Add its app credentials under Integrations first.
                            </span>
                        @elseif ($source['connected'])
                            <x-filament::button size="sm" wire:click="loadPlaylists('{{ $source['key'] }}')">
                                Load my playlists
                            </x-filament::button>
                        @else
                            <x-filament::button size="sm" wire:click="connectSource('{{ $source['key'] }}')">
                                Connect {{ $source['name'] }}
                            </x-filament::button>
                        @endif
                    </div>
                </div>
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
                                </div>
                                <x-filament::button
                                    size="sm"
                                    color="gray"
                                    wire:click="importFromSource('spotify', '{{ $pl['id'] }}')"
                                    wire:loading.attr="disabled"
                                >
                                    Import
                                </x-filament::button>
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
                                <div class="flex items-center gap-2">
                                    <x-filament::input.wrapper class="w-48">
                                        <x-filament::input type="text" wire:model="resolveTo.{{ $index }}" placeholder="Library track id" />
                                    </x-filament::input.wrapper>
                                    <x-filament::button size="sm" color="gray" wire:click="resolve({{ $index }})">Match</x-filament::button>
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
