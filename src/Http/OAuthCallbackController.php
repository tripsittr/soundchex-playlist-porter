<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\PlaylistPorter\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use SoundChex\PlaylistPorter\Services\Sources\PlaylistSourceRegistry;

/**
 * The browser side of a streaming-service OAuth connection (S-312).
 *
 * `start` sends the signed-in admin off to the service's consent page — a real
 * navigation from a link the user clicked, so the browser does not block it the
 * way it blocks a scripted `window.open` after a Livewire round-trip. The service
 * then returns to `callback` with a code, which is exchanged for tokens; the user
 * is bounced back to the Import Playlist page either way.
 */
class OAuthCallbackController extends Controller
{
    /**
     * Begin the connection: mint a state tied to this user and redirect the
     * browser to the service's authorisation page.
     */
    public function start(string $source, PlaylistSourceRegistry $registry): mixed
    {
        $connector = $registry->get($source);
        $back = URL::to('/admin/import-playlist');

        if ($connector === null || ! $connector->isConfigured()) {
            return redirect($back.'?connected=0');
        }

        $state = \Illuminate\Support\Str::random(40);
        cache()->put("playlist-oauth:{$state}", Auth::id(), now()->addMinutes(15));

        $redirect = URL::route('playlist-porter.oauth.callback', ['source' => $source]);

        return redirect()->away($connector->authorizationUrl($redirect, $state));
    }

    public function callback(Request $request, string $source, PlaylistSourceRegistry $registry): mixed
    {
        $connector = $registry->get($source);
        $back = URL::to('/admin/import-playlist');

        if ($connector === null || ! $connector->isConfigured()) {
            return redirect($back)->with('error', 'That service is not set up.');
        }

        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');

        // The state must be the one issued to the signed-in user who started it.
        if ($code === '' || (int) cache()->pull("playlist-oauth:{$state}") !== Auth::id()) {
            return redirect($back.'?connected=0');
        }

        try {
            $redirect = URL::route('playlist-porter.oauth.callback', ['source' => $source]);
            $connector->connect($code, $redirect);
        } catch (\Throwable $e) {
            report($e);

            return redirect($back.'?connected=0');
        }

        return redirect($back.'?connected='.$source);
    }
}
