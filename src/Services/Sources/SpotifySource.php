<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\PlaylistPorter\Services\Sources;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use SoundChex\PlaylistPorter\Services\ImportedTrack;
use SoundChex\PlaylistPorter\Services\PlaylistSourceException;

/**
 * Imports playlists from Spotify (S-312).
 *
 * Authorization Code flow: the operator registers a Spotify app once (client id
 * and secret, stored in settings), and each user authorises their own account.
 * Spotify's playlist tracks carry an ISRC and the Spotify track id, so a ported
 * Spotify playlist matches the library decisively rather than by fuzzy text.
 *
 * Tokens are stored per user (access + refresh) and refreshed transparently when
 * the access token has expired.
 */
class SpotifySource implements PlaylistSource
{
    private const AUTH_URL = 'https://accounts.spotify.com/authorize';

    private const TOKEN_URL = 'https://accounts.spotify.com/api/token';

    private const API = 'https://api.spotify.com/v1';

    /** The scopes needed to read a user's playlists. */
    private const SCOPES = 'playlist-read-private playlist-read-collaborative';

    public function __construct(private SettingsService $settings) {}

    public function key(): string
    {
        return 'spotify';
    }

    public function name(): string
    {
        return 'Spotify';
    }

    public function isConfigured(): bool
    {
        return filled($this->clientId()) && filled($this->clientSecret());
    }

    public function isConnected(): bool
    {
        return filled($this->settings->get($this->tokenKey('refresh')));
    }

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPES,
            'state' => $state,
        ]);
    }

    public function connect(string $code, string $redirectUri): void
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
        ]);

        $response->throw();
        $this->storeTokens($response->json());
    }

    public function disconnect(): void
    {
        $this->settings->forget($this->tokenKey('access'));
        $this->settings->forget($this->tokenKey('refresh'));
        $this->settings->forget($this->tokenKey('expires'));
    }

    public function playlists(): array
    {
        $out = [];
        $url = self::API.'/me/playlists?limit=50';

        // Which account is connected, so a playlist it cannot read can be
        // flagged before the import rather than failing with a 403.
        $me = $this->get(self::API.'/me')['id'] ?? null;

        // Spotify paginates with a `next` url; follow it to the end.
        while ($url !== null) {
            $body = $this->get($url);

            foreach ($body['items'] ?? [] as $playlist) {
                if (! is_array($playlist)) {
                    continue;
                }

                $owner = $playlist['owner']['id'] ?? null;

                $out[] = [
                    'id' => (string) ($playlist['id'] ?? ''),
                    'name' => (string) ($playlist['name'] ?? 'Untitled'),
                    // `items.total` on the current API; `tracks.total` on the
                    // older shape. Accept either so the count is never blank.
                    'track_count' => $playlist['items']['total']
                        ?? $playlist['tracks']['total']
                        ?? null,
                    // A development-mode app may only read what this account
                    // created; the picker greys the rest out.
                    'readable' => $me === null || $owner === null || $owner === $me,
                ];
            }

            $url = $body['next'] ?? null;
        }

        return $out;
    }

    public function fetch(string $playlistId): array
    {
        $meta = $this->get(self::API."/playlists/{$playlistId}?fields=name");
        $name = (string) ($meta['name'] ?? 'Spotify playlist');

        $tracks = [];

        // `/items`, not `/tracks`: the older path answers 403 Forbidden for an
        // app in Spotify's development mode, even reading a playlist the user
        // owns. `/items` returns the same rows and paginates the same way.
        $url = self::API."/playlists/{$playlistId}/items?limit=100&fields=next,items(item(name,duration_ms,external_ids(isrc),id,artists(name),album(name)))";

        while ($url !== null) {
            $body = $this->get($url);

            foreach ($body['items'] ?? [] as $row) {
                // `/items` names the row's payload `item`; the older `/tracks`
                // called it `track`. Accept either, so a future move back does
                // not silently import nothing.
                $track = $row['item'] ?? $row['track'] ?? null;

                if (! is_array($track) || blank($track['name'] ?? null)) {
                    continue; // a removed/unavailable track, or a podcast episode
                }

                $tracks[] = new ImportedTrack(
                    title: $track['name'],
                    artist: $this->primaryArtist($track['artists'] ?? []),
                    album: $track['album']['name'] ?? null,
                    isrc: $track['external_ids']['isrc'] ?? null,
                    spotifyId: $track['id'] ?? null,
                    durationMs: isset($track['duration_ms']) ? (int) $track['duration_ms'] : null,
                    sourceLabel: $track['name'],
                );
            }

            $url = $body['next'] ?? null;
        }

        return ['name' => $name, 'tracks' => $tracks];
    }

    /**
     * A GET against the Spotify API with the current access token, refreshing it
     * once and retrying if the token has expired.
     */
    private function get(string $url): array
    {
        $response = Http::withToken($this->accessToken())->get($url);

        if ($response->status() === 401) {
            $this->refresh();
            $response = Http::withToken($this->accessToken())->get($url);
        }

        // Spotify answers 403 for a playlist the connected account did not
        // create while the Spotify app is in development mode — reading
        // someone else's playlist, however public, needs an extended quota
        // that is not open to individual developers. The raw "Forbidden" said
        // none of that, so name the cause here.
        if ($response->status() === 403) {
            throw new PlaylistSourceException(
                'Spotify refused that playlist. While your Spotify app is in development mode it can '
                .'only read playlists the connected account created. Copy the playlist to your own '
                .'account in Spotify and import that, or export it as a file and import the file.'
            );
        }

        $response->throw();

        return (array) $response->json();
    }

    /** A valid access token, refreshing when the stored one has expired. */
    private function accessToken(): ?string
    {
        $expires = (int) $this->settings->get($this->tokenKey('expires'), 0);

        if ($expires > 0 && $expires <= now()->timestamp) {
            $this->refresh();
        }

        return $this->settings->get($this->tokenKey('access'));
    }

    private function refresh(): void
    {
        $refresh = $this->settings->get($this->tokenKey('refresh'));

        if (blank($refresh)) {
            return;
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
        ]);

        $response->throw();
        $this->storeTokens($response->json());
    }

    /**
     * @param  array<string, mixed>  $tokens
     */
    private function storeTokens(array $tokens): void
    {
        if (filled($tokens['access_token'] ?? null)) {
            $this->settings->set($this->tokenKey('access'), $tokens['access_token'], encrypt: true);
        }

        // A refresh token is only returned on the first exchange; keep the old
        // one when a refresh response omits it.
        if (filled($tokens['refresh_token'] ?? null)) {
            $this->settings->set($this->tokenKey('refresh'), $tokens['refresh_token'], encrypt: true);
        }

        if (isset($tokens['expires_in'])) {
            $this->settings->set($this->tokenKey('expires'), now()->timestamp + (int) $tokens['expires_in']);
        }
    }

    /** @param array<int, array<string, mixed>> $artists */
    private function primaryArtist(array $artists): ?string
    {
        $names = array_filter(array_map(fn ($a) => $a['name'] ?? null, $artists));

        return $names === [] ? null : implode(', ', $names);
    }

    private function clientId(): ?string
    {
        return $this->settings->get('spotify.client_id');
    }

    private function clientSecret(): ?string
    {
        return $this->settings->get('spotify.client_secret');
    }

    /** Per-user token keys, so each account's Spotify connection is its own. */
    private function tokenKey(string $part): string
    {
        return 'spotify.user.'.Auth::id().'.'.$part;
    }
}
