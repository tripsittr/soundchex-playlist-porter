<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\PlaylistPorter\Filament;

use App\Filament\Concerns\RestrictsToServerAdmins;
use App\Models\MediaItem;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use SoundChex\PlaylistPorter\Models\PlaylistImport;
use SoundChex\PlaylistPorter\Services\OAuthRedirect;
use SoundChex\PlaylistPorter\Services\PlaylistImportService;
use SoundChex\PlaylistPorter\Services\Sources\PlaylistSourceRegistry;
use UnitEnum;

/**
 * Import a playlist from a file (S-311).
 *
 * A deliberately plain, custom page — not a generated Filament form — rendered
 * from this plugin's own Blade view: a file picker, and afterwards the result of
 * the port, with the tracks that could not be matched listed for the admin to
 * resolve by picking a library item. It drives the shared porting engine
 * (PlaylistImportService, S-310), the same one the mobile app uses.
 */
class ImportPlaylist extends Page
{
    use RestrictsToServerAdmins;
    use WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownOnSquareStack;

    protected static string|UnitEnum|null $navigationGroup = 'Library';

    protected static ?string $title = 'Import Playlist';

    protected static ?string $navigationLabel = 'Import Playlist';

    protected string $view = 'playlist-porter::import-playlist';

    /** The uploaded playlist file (Livewire temporary upload). */
    public $file;

    /** An optional name for the resulting playlist. */
    public string $name = '';

    /** The finished import, once one has run — drives the results panel. */
    public ?int $importId = null;

    /** For each unmatched row, the library item id the admin picked to resolve it. */
    public array $resolveTo = [];

    /**
     * Parse and match the uploaded file, creating the playlist.
     */
    public function import(PlaylistImportService $service): void
    {
        $this->validate([
            'file' => ['required', 'file', 'max:5120'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $contents = (string) file_get_contents($this->file->getRealPath());
        $extension = strtolower($this->file->getClientOriginalExtension());

        try {
            $parsed = $service->parseFile($contents, $extension);
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        if ($parsed['tracks'] === []) {
            Notification::make()->title('No tracks found in that file.')->danger()->send();

            return;
        }

        $name = $this->name !== ''
            ? $this->name
            : ($parsed['name'] ?? pathinfo($this->file->getClientOriginalName(), PATHINFO_FILENAME));

        $import = PlaylistImport::create([
            'user_id' => Auth::id(),
            'source' => 'file',
            'source_format' => $parsed['format'],
            'name' => $name,
            'status' => PlaylistImport::STATUS_PENDING,
            'total_tracks' => count($parsed['tracks']),
        ]);

        // The admin is present and watching, so run it now rather than queue —
        // even a long playlist is a few seconds of matching.
        $service->run($import, $parsed['tracks'], $name);

        $this->importId = $import->id;
        $this->file = null;
        $this->name = '';
        $this->resolveTo = [];

        $fresh = $import->fresh();
        Notification::make()
            ->title("Imported \"{$fresh->name}\"")
            ->body("{$fresh->matched_tracks} of {$fresh->total_tracks} tracks matched your library.")
            ->success()
            ->send();
    }

    /**
     * Resolve one unmatched track by attaching the chosen library item to the
     * playlist and dropping it from the unmatched list.
     */
    public function resolve(int $index): void
    {
        $import = $this->currentImport();

        if ($import === null || $import->collection === null) {
            return;
        }

        $itemId = (int) ($this->resolveTo[$index] ?? 0);
        $item = MediaItem::find($itemId);

        if ($item === null) {
            Notification::make()->title('Pick a track to match it to first.')->warning()->send();

            return;
        }

        $unmatched = $import->unmatched ?? [];

        if (! array_key_exists($index, $unmatched)) {
            return;
        }

        $next = (int) $import->collection->mediaItems()->max('sort_order') + 1;
        $import->collection->mediaItems()->syncWithoutDetaching([$item->id => ['sort_order' => $next]]);

        unset($unmatched[$index], $this->resolveTo[$index]);
        $import->update([
            'unmatched' => array_values($unmatched),
            'matched_tracks' => $import->matched_tracks + 1,
        ]);

        Notification::make()->title('Matched — added to the playlist.')->success()->send();
    }

    /**
     * Library tracks matching a search string, for the resolve picker. Keyed by
     * id → "Title — Artist", so the Blade can offer them in a select.
     *
     * @return array<int, string>
     */
    public function candidates(string $search): array
    {
        $search = trim($search);

        if (mb_strlen($search) < 2) {
            return [];
        }

        return MediaItem::query()
            ->where('type', \App\Enums\MediaItemType::Music)
            ->where('title', 'like', "%{$search}%")
            ->with('musicMetadata')
            ->limit(20)
            ->get()
            ->mapWithKeys(fn (MediaItem $i): array => [
                $i->id => $i->title.($i->musicMetadata?->artist ? ' — '.$i->musicMetadata->artist : ''),
            ])
            ->all();
    }

    public function currentImport(): ?PlaylistImport
    {
        if ($this->importId === null) {
            return null;
        }

        $import = PlaylistImport::find($this->importId);

        return $import !== null && $import->user_id === Auth::id() ? $import : null;
    }

    // MARK: - Streaming services (Spotify, …) — S-312

    /** The service's playlists once connected, for the picker. */
    public array $servicePlaylists = [];

    /** The Spotify app credentials being entered, for the setup form. */
    public string $spotifyClientId = '';

    public string $spotifyClientSecret = '';

    /** The operator's override for the OAuth base URL, for the setup form. */
    public string $oauthBaseUrl = '';

    /**
     * The redirect URI to register in the Spotify app — the browser callback the
     * service returns to. Shown so it can be copied exactly; Spotify rejects a
     * mismatch. This is the same value the connect flow actually sends (S-322),
     * so what is displayed is always what is used.
     */
    public function spotifyRedirectUri(): string
    {
        return app(OAuthRedirect::class)->for('spotify');
    }

    /** The base URL currently in effect, shown as the field's placeholder. */
    public function oauthBaseUrlDefault(): string
    {
        return app(OAuthRedirect::class)->baseUrl();
    }

    /**
     * Pin the base URL the services return to. Needed when the browser reaches
     * this server by one address but the registered redirect URI is another —
     * this server answers on a tailnet host, a public Funnel host and loopback
     * at once, and only one of those can be registered.
     */
    public function saveOauthBaseUrl(): void
    {
        $this->validate([
            'oauthBaseUrl' => ['required', 'url:http,https', 'max:255'],
        ]);

        app(SettingsService::class)->set(
            OAuthRedirect::BASE_SETTING,
            rtrim(trim($this->oauthBaseUrl), '/'),
        );

        Notification::make()
            ->title('Redirect URI updated')
            ->body('Make sure this exact URI is registered in your Spotify app.')
            ->success()
            ->send();
    }

    /**
     * Save the operator's Spotify app credentials, so users can connect. The
     * secret is stored encrypted. These are the same keys SpotifySource reads.
     */
    public function saveSpotifyCredentials(): void
    {
        $this->validate([
            'spotifyClientId' => ['required', 'string', 'max:255'],
            'spotifyClientSecret' => ['required', 'string', 'max:255'],
        ]);

        $settings = app(SettingsService::class);
        $settings->set('spotify.client_id', trim($this->spotifyClientId));
        $settings->set('spotify.client_secret', trim($this->spotifyClientSecret), encrypt: true);

        $this->spotifyClientId = '';
        $this->spotifyClientSecret = '';

        Notification::make()
            ->title('Spotify is set up')
            ->body('You can now connect your Spotify account and import playlists.')
            ->success()
            ->send();
    }

    /**
     * The available streaming services and their state, for the "Connect a
     * service" section. Each: key, name, configured (operator set credentials),
     * connected (this user authorised it).
     *
     * @return array<int, array{key: string, name: string, configured: bool, connected: bool}>
     */
    public function sources(): array
    {
        return array_map(fn ($s): array => [
            'key' => $s->key(),
            'name' => $s->name(),
            'configured' => $s->isConfigured(),
            'connected' => $s->isConnected(),
        ], app(PlaylistSourceRegistry::class)->all());
    }

    /**
     * Begin connecting a service: opens the service's consent page in a new tab.
     * The service returns to the callback route the page handles.
     *
     * Connecting is a plain link to the `oauth start` route (see the Blade), not a
     * Livewire action — a scripted `window.open` after a server round-trip is
     * blocked by the browser as a non-user-initiated popup, which is why the
     * old button did nothing. Disconnecting stays here.
     */
    public function disconnectSource(string $key): void
    {
        $source = app(PlaylistSourceRegistry::class)->get($key);
        $source?->disconnect();
        $this->servicePlaylists = [];

        Notification::make()->title(($source?->name() ?? 'Service').' disconnected')->success()->send();
    }

    /**
     * Fetch the connected user's playlists on a service, for them to pick one.
     */
    public function loadPlaylists(string $key): void
    {
        $source = app(PlaylistSourceRegistry::class)->get($key);

        if ($source === null || ! $source->isConnected()) {
            Notification::make()->title('Connect '.($source?->name() ?? 'the service').' first.')->warning()->send();

            return;
        }

        try {
            $this->servicePlaylists = $source->playlists();
        } catch (\Throwable $e) {
            Notification::make()->title('Could not read your playlists.')->body($e->getMessage())->danger()->send();

            return;
        }

        if ($this->servicePlaylists === []) {
            Notification::make()->title('No playlists found on '.$source->name().'.')->send();
        }
    }

    /**
     * Import one of a service's playlists into the library.
     */
    public function importFromSource(string $key, string $playlistId, PlaylistImportService $service): void
    {
        $source = app(PlaylistSourceRegistry::class)->get($key);

        if ($source === null || ! $source->isConnected()) {
            return;
        }

        try {
            $fetched = $source->fetch($playlistId);
        } catch (\Throwable $e) {
            Notification::make()->title('Could not read that playlist.')->body($e->getMessage())->danger()->send();

            return;
        }

        if ($fetched['tracks'] === []) {
            Notification::make()->title('That playlist has no tracks.')->warning()->send();

            return;
        }

        $import = PlaylistImport::create([
            'user_id' => Auth::id(),
            'source' => $source->key(),
            'source_format' => $source->key(),
            'name' => $fetched['name'],
            'status' => PlaylistImport::STATUS_PENDING,
            'total_tracks' => count($fetched['tracks']),
        ]);

        $service->run($import, $fetched['tracks'], $fetched['name']);

        $this->importId = $import->id;
        $this->resolveTo = [];
        $this->servicePlaylists = [];

        $fresh = $import->fresh();
        Notification::make()
            ->title("Imported \"{$fresh->name}\"")
            ->body("{$fresh->matched_tracks} of {$fresh->total_tracks} tracks matched your library.")
            ->success()
            ->send();
    }
}
