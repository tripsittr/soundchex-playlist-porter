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
 * Completes a streaming-service OAuth connection from the browser redirect
 * (S-312).
 *
 * The service sends the user's browser here with `?code=…&state=…`; we verify the
 * state we issued, exchange the code for tokens, and bounce back to the Import
 * Playlist page with a status the page can show.
 */
class OAuthCallbackController extends Controller
{
    public function __invoke(Request $request, string $source, PlaylistSourceRegistry $registry): mixed
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
