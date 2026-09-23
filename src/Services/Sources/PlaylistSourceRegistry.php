<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\PlaylistPorter\Services\Sources;

/**
 * The streaming services playlists can be ported from (S-312).
 *
 * A small registry so the API and UI can list what is available and resolve one
 * by key. Connectors are added here as they are built; a plugin could contribute
 * more through a future seam, but the built-ins live here.
 */
class PlaylistSourceRegistry
{
    /**
     * @return array<int, PlaylistSource>
     */
    public function all(): array
    {
        return [
            app(SpotifySource::class),
            app(YouTubeMusicSource::class),
        ];
    }

    /**
     * Services the page should show as coming, with why they are not here yet.
     *
     * Listing them is honest about the plan without pretending they work: each
     * needs something SoundChex cannot provide on the operator's behalf, so a
     * greyed card that says so beats a button that fails (S-347).
     *
     * @return array<int, array{name: string, note: string}>
     */
    public function planned(): array
    {
        return [
            [
                'name' => 'Apple Music',
                'note' => 'Needs a paid Apple Developer account: playlists are read with a '
                    .'MusicKit developer token signed by your own private key.',
            ],
            [
                'name' => 'Amazon Music',
                'note' => 'Amazon has no public playlist API. Export to a file and import that '
                    .'in the meantime.',
            ],
        ];
    }

    public function get(string $key): ?PlaylistSource
    {
        foreach ($this->all() as $source) {
            if ($source->key() === $key) {
                return $source;
            }
        }

        return null;
    }
}
