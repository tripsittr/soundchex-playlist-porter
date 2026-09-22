<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Support\Facades\Route;
use SoundChex\PlaylistPorter\Http\OAuthCallbackController;

/**
 * The plugin's browser-facing OAuth return (S-312).
 *
 * A streaming service redirects the user's browser back here (a GET with a code
 * and state) after they authorise the connection. This completes the token
 * exchange and sends them back to the Import Playlist admin page. Session-guarded
 * like the rest of the admin, so the returning user is the one who started it.
 */
Route::get('/playlist-porter/oauth/{source}/callback', OAuthCallbackController::class)
    ->name('playlist-porter.oauth.callback');
