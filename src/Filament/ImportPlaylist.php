<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\PlaylistPorter\Filament;

use App\Models\MediaItem;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
    use WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownOnSquareStack;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?string $title = 'Import Playlist';

    protected static ?string $navigationLabel = 'Import Playlist';

    protected string $view = 'playlist-porter::import-playlist';

    public static function canAccess(): bool
    {
        Log::debug('playlist-porter: canAccess check', [
            'allowed' => true,
            'user_id' => Auth::id(),
        ]);

        // Panel auth middleware is the source of truth.
        return true;
    }

    public static function shouldRegisterNavigation(): bool
    {
        Log::debug('playlist-porter: shouldRegisterNavigation check', [
            'user_id' => Auth::id(),
        ]);

        return true;
    }

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
        Log::info('playlist-porter: file import requested', [
            'user_id' => Auth::id(),
            'has_file' => $this->file !== null,
            'name_override' => $this->name !== '',
        ]);

        $this->validate([
            'file' => ['required', 'file', 'max:5120'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $contents = (string) file_get_contents($this->file->getRealPath());
        $extension = strtolower($this->file->getClientOriginalExtension());

        try {
            $parsed = $service->parseFile($contents, $extension);
        } catch (\RuntimeException $e) {
            Log::warning('playlist-porter: file import parse failed', [
                'user_id' => Auth::id(),
                'extension' => $extension,
                'error' => $e->getMessage(),
            ]);

            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        if ($parsed['tracks'] === []) {
            Log::info('playlist-porter: file import had no tracks', [
                'user_id' => Auth::id(),
                'extension' => $extension,
            ]);

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

        Log::info('playlist-porter: file import created', [
            'user_id' => Auth::id(),
            'import_id' => $import->id,
            'track_count' => count($parsed['tracks']),
            'source_format' => $parsed['format'] ?? null,
        ]);

        // The admin is present and watching, so run it now rather than queue —
        // even a long playlist is a few seconds of matching.
        $service->run($import, $parsed['tracks'], $name);

        $this->importId = $import->id;
        $this->file = null;
        $this->name = '';
        $this->resolveTo = [];

        $fresh = $import->fresh();
        Log::info('playlist-porter: file import completed', [
            'user_id' => Auth::id(),
            'import_id' => $fresh?->id,
            'matched_tracks' => $fresh?->matched_tracks,
            'total_tracks' => $fresh?->total_tracks,
        ]);

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
    /**
     * Accept a candidate the matcher offered for an unmatched track — the
     * one-click path, so the common case is not a search.
     */
    public function acceptCandidate(int $index, int $mediaItemId): void
    {
        Log::debug('playlist-porter: accept candidate requested', [
            'user_id' => Auth::id(),
            'import_id' => $this->importId,
            'index' => $index,
            'media_item_id' => $mediaItemId,
        ]);

        $this->resolveTo[$index] = $mediaItemId;

        $this->resolve($index);
    }

    /**
     * Keep an uncertain match: it was already attached, so this only clears the
     * flag once a person has agreed with it.
     */
    public function confirmUncertain(int $index): void
    {
        $import = $this->currentImport();

        if ($import === null) {
            Log::debug('playlist-porter: confirm uncertain skipped (no import)', [
                'user_id' => Auth::id(),
                'index' => $index,
            ]);

            return;
        }

        $uncertain = $import->uncertain ?? [];

        if (! array_key_exists($index, $uncertain)) {
            Log::debug('playlist-porter: confirm uncertain skipped (index missing)', [
                'user_id' => Auth::id(),
                'import_id' => $import->id,
                'index' => $index,
            ]);

            return;
        }

        unset($uncertain[$index]);
        $import->update(['uncertain' => array_values($uncertain)]);
        Log::info('playlist-porter: uncertain match confirmed', [
            'user_id' => Auth::id(),
            'import_id' => $import->id,
            'index' => $index,
        ]);

        Notification::make()->title('Kept.')->success()->send();
    }

    /**
     * Reject an uncertain match: detach the song the matcher guessed at and put
     * the track back among the unmatched, so it can be resolved by hand.
     */
    public function rejectUncertain(int $index): void
    {
        $import = $this->currentImport();

        if ($import === null || $import->collection === null) {
            Log::debug('playlist-porter: reject uncertain skipped (missing import or collection)', [
                'user_id' => Auth::id(),
                'index' => $index,
                'import_id' => $this->importId,
            ]);

            return;
        }

        $uncertain = $import->uncertain ?? [];

        if (! array_key_exists($index, $uncertain)) {
            Log::debug('playlist-porter: reject uncertain skipped (index missing)', [
                'user_id' => Auth::id(),
                'import_id' => $import->id,
                'index' => $index,
            ]);

            return;
        }

        $track = $uncertain[$index];
        $import->collection->mediaItems()->detach($track['media_item_id'] ?? null);

        unset($uncertain[$index]);

        // Back to the unmatched list, minus the guess that was wrong.
        $unmatched = $import->unmatched ?? [];
        $unmatched[] = collect($track)
            ->except(['media_item_id', 'matched_title', 'reason', 'score'])
            ->all();

        $import->update([
            'uncertain' => array_values($uncertain),
            'unmatched' => $unmatched,
            'matched_tracks' => max(0, $import->matched_tracks - 1),
        ]);
        Log::info('playlist-porter: uncertain match rejected', [
            'user_id' => Auth::id(),
            'import_id' => $import->id,
            'index' => $index,
        ]);

        Notification::make()->title('Removed from the playlist.')->success()->send();
    }

    public function resolve(int $index): void
    {
        $import = $this->currentImport();

        if ($import === null || $import->collection === null) {
            Log::debug('playlist-porter: resolve skipped (missing import or collection)', [
                'user_id' => Auth::id(),
                'import_id' => $this->importId,
                'index' => $index,
            ]);

            return;
        }

        $itemId = (int) ($this->resolveTo[$index] ?? 0);
        $item = MediaItem::find($itemId);

        if ($item === null) {
            Log::warning('playlist-porter: resolve failed (missing media item)', [
                'user_id' => Auth::id(),
                'import_id' => $import->id,
                'index' => $index,
                'media_item_id' => $itemId,
            ]);

            Notification::make()->title('Pick a track to match it to first.')->warning()->send();

            return;
        }

        $unmatched = $import->unmatched ?? [];

        if (! array_key_exists($index, $unmatched)) {
            Log::debug('playlist-porter: resolve skipped (index missing)', [
                'user_id' => Auth::id(),
                'import_id' => $import->id,
                'index' => $index,
            ]);

            return;
        }

        $next = (int) $import->collection->mediaItems()->max('sort_order') + 1;
        $import->collection->mediaItems()->syncWithoutDetaching([$item->id => ['sort_order' => $next]]);

        unset($unmatched[$index], $this->resolveTo[$index]);
        $import->update([
            'unmatched' => array_values($unmatched),
            'matched_tracks' => $import->matched_tracks + 1,
        ]);
        Log::info('playlist-porter: unmatched track resolved', [
            'user_id' => Auth::id(),
            'import_id' => $import->id,
            'index' => $index,
            'media_item_id' => $item->id,
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
            ->mapWithKeys(fn(MediaItem $i): array => [
                $i->id => $i->title . ($i->musicMetadata?->artist ? ' — ' . $i->musicMetadata->artist : ''),
            ])
            ->all();
    }

    /**
     * The import being shown, surviving a reload (S-335).
     *
     * The id lived only in Livewire's component state, so navigating away or
     * refreshing lost the match list and the unresolved tracks with it — work
     * the user was part-way through. Falling back to this user's most recent
     * import means the page reopens where they left it.
     */
    public function currentImport(): ?PlaylistImport
    {
        if ($this->importId !== null) {
            $import = PlaylistImport::find($this->importId);

            if ($import !== null && $import->user_id === Auth::id()) {
                return $import;
            }
        }

        $latest = PlaylistImport::query()
            ->where('user_id', Auth::id())
            ->latest('id')
            ->first();

        if ($latest !== null) {
            $this->importId = $latest->id;
        }

        return $latest;
    }

    // MARK: - Streaming services (Spotify, …) — S-312

    /** The service's playlists once connected, for the picker. */
    public array $servicePlaylists = [];

    /** Re-reads the picker's playlists from the service, ignoring the cache. */
    public function refreshPlaylists(string $key): void
    {
        $this->loadPlaylists($key, force: true);

        Notification::make()->title('Playlists refreshed.')->success()->send();
    }

    /** Where a source's playlist listing is cached, per user. */
    private function playlistCacheKey(string $source): string
    {
        return 'playlist-porter:playlists:'.Auth::id().':'.$source;
    }

    /** The Spotify app credentials being entered, for the setup form. */
    public string $spotifyClientId = '';

    public string $spotifyClientSecret = '';

    /** The operator's override for the OAuth base URL, for the setup form. */
    public string $oauthBaseUrl = '';

    /**
     * Show the override that is actually in force, rather than an empty box
     * beside a placeholder, so the field states where Spotify is being sent.
     */
    public function mount(): void
    {
        $this->oauthBaseUrl = (string) app(SettingsService::class)
            ->get(OAuthRedirect::BASE_SETTING, '');

        // Show the picker straight away when this user has listed playlists
        // before, rather than making them press Load again (S-335).
        foreach (app(PlaylistSourceRegistry::class)->all() as $source) {
            $cached = Cache::get($this->playlistCacheKey($source->key()));

            if (is_array($cached) && $cached !== []) {
                $this->servicePlaylists = $cached;
                break;
            }
        }

        Log::debug('playlist-porter: page mounted', [
            'user_id' => Auth::id(),
            'oauth_base_override_set' => $this->oauthBaseUrl !== '',
            'cached_playlists' => count($this->servicePlaylists),
        ]);
    }

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

    /** The server's own configured address, used when no override is set. */
    public function oauthBaseUrlDefault(): string
    {
        return app(OAuthRedirect::class)->defaultBaseUrl();
    }

    /**
     * Pin the base URL the services return to. Needed when the browser reaches
     * this server by one address but the registered redirect URI is another —
     * this server answers on a tailnet host, a public Funnel host and loopback
     * at once, and only one of those can be registered.
     *
     * Emptying the field clears the override and falls back to `APP_URL`;
     * without that there would be no way back to the default once one is set.
     */
    public function saveOauthBaseUrl(): void
    {
        $this->validate([
            'oauthBaseUrl' => ['nullable', 'url:http,https', 'max:255'],
        ]);

        $base = rtrim(trim($this->oauthBaseUrl), '/');

        app(SettingsService::class)->set(OAuthRedirect::BASE_SETTING, $base);
        $this->oauthBaseUrl = $base;

        Notification::make()
            ->title($base === '' ? 'Using the server address' : 'Redirect URI updated')
            ->body('Register this exact URI in your Spotify app: ' . $this->spotifyRedirectUri())
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
        return array_map(fn($s): array => [
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

        Log::info('playlist-porter: source disconnected', [
            'user_id' => Auth::id(),
            'source' => $key,
        ]);

        Notification::make()->title(($source?->name() ?? 'Service') . ' disconnected')->success()->send();
    }

    /**
     * Fetch the connected user's playlists on a service, for them to pick one.
     */
    /**
     * The picker's playlists for a source.
     *
     * @param  bool  $force  Skip the cache — the Refresh control.
     */
    public function loadPlaylists(string $key, bool $force = false): void
    {
        $source = app(PlaylistSourceRegistry::class)->get($key);

        if ($source === null || ! $source->isConnected()) {
            Log::warning('playlist-porter: load playlists denied (source not connected)', [
                'user_id' => Auth::id(),
                'source' => $key,
            ]);

            Notification::make()->title('Connect ' . ($source?->name() ?? 'the service') . ' first.')->warning()->send();

            return;
        }

        // Served from cache unless the user asked for a refresh: listing
        // playlists is a paged round-trip to the service, and it was repeated
        // on every visit (S-335).
        $cacheKey = $this->playlistCacheKey($key);

        if (! $force && ($cached = Cache::get($cacheKey)) !== null) {
            $this->servicePlaylists = $cached;

            return;
        }

        try {
            $this->servicePlaylists = $source->playlists();
            Cache::put($cacheKey, $this->servicePlaylists, now()->addHours(6));
        } catch (\Throwable $e) {
            Log::error('playlist-porter: load playlists failed', [
                'user_id' => Auth::id(),
                'source' => $key,
                'error' => $e->getMessage(),
            ]);

            Notification::make()->title('Could not read your playlists.')->body($e->getMessage())->danger()->send();

            return;
        }

        if ($this->servicePlaylists === []) {
            Log::info('playlist-porter: load playlists returned empty', [
                'user_id' => Auth::id(),
                'source' => $key,
            ]);

            Notification::make()->title('No playlists found on ' . $source->name() . '.')->send();
        }
    }

    /**
     * Import one of a service's playlists into the library.
     */
    public function importFromSource(string $key, string $playlistId, PlaylistImportService $service): void
    {
        $source = app(PlaylistSourceRegistry::class)->get($key);

        Log::info('playlist-porter: source import requested', [
            'user_id' => Auth::id(),
            'source' => $key,
            'playlist_id' => $playlistId,
        ]);

        if ($source === null || ! $source->isConnected()) {
            Log::warning('playlist-porter: source import denied (source not connected)', [
                'user_id' => Auth::id(),
                'source' => $key,
            ]);

            return;
        }

        try {
            $fetched = $source->fetch($playlistId);
        } catch (\Throwable $e) {
            Log::error('playlist-porter: source import fetch failed', [
                'user_id' => Auth::id(),
                'source' => $key,
                'playlist_id' => $playlistId,
                'error' => $e->getMessage(),
            ]);

            Notification::make()->title('Could not read that playlist.')->body($e->getMessage())->danger()->send();

            return;
        }

        if ($fetched['tracks'] === []) {
            Log::info('playlist-porter: source import had no tracks', [
                'user_id' => Auth::id(),
                'source' => $key,
                'playlist_id' => $playlistId,
            ]);

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

        Log::info('playlist-porter: source import created', [
            'user_id' => Auth::id(),
            'source' => $key,
            'import_id' => $import->id,
            'track_count' => count($fetched['tracks']),
        ]);

        $service->run($import, $fetched['tracks'], $fetched['name']);

        $this->importId = $import->id;
        $this->resolveTo = [];
        $this->servicePlaylists = [];

        $fresh = $import->fresh();
        Log::info('playlist-porter: source import completed', [
            'user_id' => Auth::id(),
            'source' => $key,
            'import_id' => $fresh?->id,
            'matched_tracks' => $fresh?->matched_tracks,
            'total_tracks' => $fresh?->total_tracks,
        ]);

        Notification::make()
            ->title("Imported \"{$fresh->name}\"")
            ->body("{$fresh->matched_tracks} of {$fresh->total_tracks} tracks matched your library.")
            ->success()
            ->send();
    }
}
