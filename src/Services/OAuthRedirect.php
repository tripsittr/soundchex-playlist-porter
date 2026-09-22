<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\PlaylistPorter\Services;

use App\Http\Middleware\SetAppUrl;
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
     * The operator's override, else the server's configured `APP_URL`.
     *
     * Not `config('app.url')`: `SetAppUrl` overwrites that on every web request
     * with the address the request arrived on, which is the drift this class
     * exists to prevent. It stashes the configured value under
     * `app.configured_url` first, which is what is read here — and unlike
     * `env()`, that still holds once the config is cached.
     *
     * Trailing slashes are trimmed so the joined URI never doubles one; the
     * services compare exact strings.
     */
    public function baseUrl(): string
    {
        $configured = trim((string) $this->settings->get(self::BASE_SETTING, ''));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return $this->defaultBaseUrl();
    }

    /**
     * The server's own configured address, ignoring any override — what the
     * redirect URI falls back to, and what the settings form offers.
     */
    public function defaultBaseUrl(): string
    {
        $appUrl = config(SetAppUrl::CONFIGURED_URL) ?? config('app.url');

        return rtrim((string) $appUrl, '/');
    }
}
