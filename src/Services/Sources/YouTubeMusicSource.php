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
 * Imports playlists from YouTube Music (S-347).
 *
 * YouTube Music has no API of its own; its playlists are YouTube playlists, so
 * this reads them through the YouTube Data API with the user's own OAuth
 * consent. The operator registers one Google Cloud OAuth client (client id and
 * secret) exactly as they do for Spotify.
 *
 * What that API gives us is a video title and a channel — not an ISRC, and not
 * a clean artist/title split. Most music videos are titled "Artist - Title",
 * so that is parsed when it holds, and the rest is left to the track matcher's
 * fuzzy pass. Matching is therefore less certain than Spotify's, which is
 * exactly why the importer grades its matches rather than trusting them.
 */
class YouTubeMusicSource implements PlaylistSource
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API = 'https://www.googleapis.com/youtube/v3';

    /** Read-only access to the signed-in account's own playlists. */
    private const SCOPES = 'https://www.googleapis.com/auth/youtube.readonly';

    public function __construct(private SettingsService $settings) {}

    public function key(): string
    {
        return 'youtube-music';
    }

    public function name(): string
    {
        return 'YouTube Music';
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
            // Google only returns a refresh token when both are asked for, and
            // only on the first consent — without them the connection dies at
            // the first token expiry.
            'access_type' => 'offline',
            'prompt' => 'consent',
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
        $pageToken = null;

        do {
            $body = $this->get(self::API.'/playlists?'.http_build_query(array_filter([
                'part' => 'snippet,contentDetails',
                'mine' => 'true',
                'maxResults' => 50,
                'pageToken' => $pageToken,
            ])));

            foreach ($body['items'] ?? [] as $playlist) {
                $out[] = [
                    'id' => (string) ($playlist['id'] ?? ''),
                    'name' => (string) ($playlist['snippet']['title'] ?? 'Untitled'),
                    'track_count' => $playlist['contentDetails']['itemCount'] ?? null,
                    // Everything listed here belongs to the signed-in account.
                    'readable' => true,
                ];
            }

            $pageToken = $body['nextPageToken'] ?? null;
        } while ($pageToken !== null);

        return $out;
    }

    public function fetch(string $playlistId): array
    {
        $meta = $this->get(self::API.'/playlists?'.http_build_query([
            'part' => 'snippet',
            'id' => $playlistId,
        ]));

        $name = (string) ($meta['items'][0]['snippet']['title'] ?? 'YouTube Music playlist');

        $tracks = [];
        $pageToken = null;

        do {
            $body = $this->get(self::API.'/playlistItems?'.http_build_query(array_filter([
                'part' => 'snippet',
                'playlistId' => $playlistId,
                'maxResults' => 50,
                'pageToken' => $pageToken,
            ])));

            foreach ($body['items'] ?? [] as $row) {
                $snippet = $row['snippet'] ?? null;

                if (! is_array($snippet) || blank($snippet['title'] ?? null)) {
                    continue; // a deleted or private video
                }

                // "Private video" and "Deleted video" are placeholders, not songs.
                if (in_array($snippet['title'], ['Private video', 'Deleted video'], true)) {
                    continue;
                }

                [$artist, $title] = $this->splitTitle(
                    (string) $snippet['title'],
                    (string) ($snippet['videoOwnerChannelTitle'] ?? ''),
                );

                $tracks[] = new ImportedTrack(
                    title: $title,
                    artist: $artist,
                    album: null,
                    isrc: null,
                    durationMs: null,
                    sourceLabel: $snippet['title'],
                );
            }

            $pageToken = $body['nextPageToken'] ?? null;
        } while ($pageToken !== null);

        return ['name' => $name, 'tracks' => $tracks];
    }

    /**
     * Splits a video title into artist and song.
     *
     * Music videos are overwhelmingly "Artist - Title". When that shape is
     * absent the channel name is the best guess at the artist — a topic channel
     * is literally "Artist - Topic" — and the whole title stands as the song.
     *
     * @return array{0: ?string, 1: string}
     */
    private function splitTitle(string $videoTitle, string $channel): array
    {
        // Strip the noise uploaders add: "(Official Video)", "[HD]", "(Lyrics)".
        $clean = preg_replace(
            '/[\(\[][^\)\]]*\b(official|video|audio|lyrics?|hd|hq|mv|visualizer|remaster(ed)?)\b[^\)\]]*[\)\]]/iu',
            '',
            $videoTitle,
        ) ?? $videoTitle;

        $clean = trim($clean);

        foreach ([' - ', ' – ', ' — ', ' | '] as $separator) {
            if (! str_contains($clean, $separator)) {
                continue;
            }

            [$left, $right] = array_map('trim', explode($separator, $clean, 2));

            if ($left !== '' && $right !== '') {
                return [$left, $right];
            }
        }

        // A "… - Topic" channel names the artist outright.
        $artist = trim((string) preg_replace('/\s*-\s*Topic$/u', '', $channel));

        return [$artist !== '' ? $artist : null, $clean !== '' ? $clean : $videoTitle];
    }

    /**
     * A GET against the API with the current access token, refreshing once and
     * retrying if it has expired.
     *
     * @return array<string, mixed>
     */
    private function get(string $url): array
    {
        $response = Http::withToken($this->accessToken())->get($url);

        if ($response->status() === 401) {
            $this->refresh();
            $response = Http::withToken($this->accessToken())->get($url);
        }

        if ($response->status() === 403) {
            throw new PlaylistSourceException(
                'YouTube refused that request. This usually means the YouTube Data API is not '
                .'enabled on your Google Cloud project, or its daily quota is spent.'
            );
        }

        $response->throw();

        return (array) $response->json();
    }

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

        // Google returns a refresh token only on the first consent; keep the
        // stored one when a refresh response omits it.
        if (filled($tokens['refresh_token'] ?? null)) {
            $this->settings->set($this->tokenKey('refresh'), $tokens['refresh_token'], encrypt: true);
        }

        if (isset($tokens['expires_in'])) {
            $this->settings->set($this->tokenKey('expires'), now()->timestamp + (int) $tokens['expires_in']);
        }
    }

    private function clientId(): ?string
    {
        return $this->settings->get('youtube.client_id');
    }

    private function clientSecret(): ?string
    {
        return $this->settings->get('youtube.client_secret');
    }

    /** Per-user token keys, so each account's connection is its own. */
    private function tokenKey(string $part): string
    {
        return 'youtube.user.'.Auth::id().'.'.$part;
    }
}
