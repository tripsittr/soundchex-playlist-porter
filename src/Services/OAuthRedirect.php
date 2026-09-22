<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\PlaylistPorter\Services;

use App\Services\SettingsService;
use Illuminate\Support\Facades\URL;

/**
 * The one redirect URI this server uses for streaming-service OAuth (S-322).
 *
 * A redirect URI has to match the one registered in the service's app dashboard
 * character for character, and a service is registered with exactly one. That
 * sits badly with this server, which is deliberately reachable several ways at
 * once — a tailnet host on :8443, a public Funnel host on :443, the loopback
 * :8000 the desktop shell talks to. `SetAppUrl` rebases generated URLs onto
 * whichever of those the current request arrived on, which is right for assets
 * and redirects and wrong here: it silently produced a different redirect_uri
 * per access path, and every path but the registered one was rejected with
 * "redirect_uri: Not matching configuration".
 *
 * So the callback is pinned to a stored base URL instead of the live request.
 * It defaults to `APP_URL` (which `server:detect-address` already keeps pointed
 * at a real address) and the operator can override it, which is what makes this
 * work when the browser reaches the app one way and the registered URI is
 * another. Both the URI shown for copying and the one sent to the service come
 * from here, so the two cannot drift apart.
 */
class OAuthRedirect
{
    /** Setting holding the base URL the service should return to. */
    public const BASE_SETTING = 'playlist_porter.oauth_base_url';

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * The absolute callback URI for a source, e.g.
     * `https://host:8443/playlist-porter/oauth/spotify/callback`.
     */
    public function for(string $source): string
    {
        $path = URL::route(
            'playlist-porter.oauth.callback',
            ['source' => $source],
            absolute: false,
        );

        return $this->baseUrl().$path;
    }

    /**
     * The configured base, or `APP_URL`. Read from the environment rather than
     * `config('app.url')`, because `SetAppUrl` overwrites that config value with
     * the current request's host on every web request — the very drift this
     * class exists to avoid. Trailing slashes are trimmed so the joined URI
     * never doubles one; the services compare exact strings.
     */
    public function baseUrl(): string
    {
        $configured = trim((string) $this->settings->get(self::BASE_SETTING, ''));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return rtrim((string) env('APP_URL', config('app.url')), '/');
    }
}
