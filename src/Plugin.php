<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\PlaylistPorter;

use App\Plugins\Contracts\SoundChexPlugin;
use App\Plugins\Registry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use SoundChex\PlaylistPorter\Filament\ImportPlaylist;

/**
 * Playlist porting, as a first-party bundled plugin (S-311).
 *
 * Phase 2 of the porting feature (S-309): the desktop/server face of it. The
 * porting engine — parsing, matching, playlist creation — lives entirely in this
 * plugin (S-315): its services, job, model, migration, API routes and the admin
 * screen that drives them, contributed through the plugin seams rather than added
 * to core. Bundled and always on, so a fresh server can import a playlist from
 * day one; disabling it removes the table, the endpoints and the page together.
 */
class Plugin implements SoundChexPlugin
{
    public function getId(): string
    {
        return 'soundchex.playlist-porter';
    }

    public function register(Registry $registry): void
    {
        Log::info('playlist-porter: plugin register start', [
            'plugin_id' => $this->getId(),
            'entry' => self::class,
        ]);

        // The page's blade lives in this plugin, under a `playlist-porter` view
        // namespace so `playlist-porter::import-playlist` resolves.
        View::addNamespace('playlist-porter', __DIR__ . '/../resources/views');

        // …and the same path as an anonymous *component* namespace, so the
        // blade can write <x-playlist-porter::hint />. A view namespace alone
        // does not give Blade the component tag (S-331).
        Blade::anonymousComponentNamespace(
            'playlist-porter::components',
            'playlist-porter',
        );

        // Add the Import Playlist page to the admin. The page gates itself to
        // server admins via its own concern; this only makes Filament aware of it.
        $registry->adminPage(ImportPlaylist::class);

        // The plugin owns its own schema — the playlist_imports table — so it runs
        // with the core migrations and disabling the plugin takes its table with it.
        $registry->migrations(__DIR__ . '/../database/migrations');

        // The plugin owns its own API too: the /playlists/imports and
        // /playlists/sources endpoints. Under the same api/v1 prefix, the `api`
        // middleware group and the auth:sanctum guard the core API uses, so the
        // URLs and behaviour are unchanged. The `api` group is essential: it
        // carries SubstituteBindings, which resolves route-model params like
        // {import} — without it every bound route (show, resolve) 404s.
        $registry->routes(__DIR__ . '/../routes/api.php', prefix: 'api/v1', middleware: ['api', 'auth:sanctum']);

        // The browser-facing OAuth return for streaming-service connections
        // (S-312), under the `web` group so the returning user's session is
        // authenticated — the service redirects the browser here.
        $registry->routes(__DIR__ . '/../routes/web.php', middleware: ['web']);

        Log::info('playlist-porter: plugin register complete', [
            'plugin_id' => $this->getId(),
            'admin_page' => ImportPlaylist::class,
        ]);
    }

    public function boot(Registry $registry): void
    {
        Log::debug('playlist-porter: plugin boot', [
            'plugin_id' => $this->getId(),
        ]);
    }
}
