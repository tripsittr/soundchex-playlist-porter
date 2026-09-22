<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\PlaylistPorter\Services;

use App\Models\MediaItem;

/**
 * One library item a track might be, and how sure we are (S-324).
 *
 * Matching is graded rather than yes/no. An ISRC is proof; a title and artist
 * that agree while the album differs is good evidence that the library holds
 * the same song on a different release. Both belong in the playlist, but only
 * the first should be silently trusted, so the confidence travels with the
 * match and the import decides what to do with it.
 */
final class TrackMatch
{
    /** Proof: a recording identifier agreed. */
    public const CERTAIN = 'certain';

    /** Title and artist agree, and nothing contradicts them. */
    public const LIKELY = 'likely';

    /** Title and artist agree but the release or length does not. */
    public const UNCERTAIN = 'uncertain';

    public function __construct(
        public readonly MediaItem $item,
        public readonly string $confidence,
        /** Why this scored as it did, for the review list. */
        public readonly string $reason,
        public readonly float $score = 0.0,
    ) {}

    /**
     * Whether this is safe to attach without a person looking at it.
     *
     * Both an identifier match and a clean title+artist match qualify: the
     * second is what carries a tags-not-ids library, and flagging all of those
     * would bury the handful that genuinely deserve a glance.
     */
    public function isCertain(): bool
    {
        return $this->confidence === self::CERTAIN
            || $this->confidence === self::LIKELY;
    }

    /**
     * Whether the import should attach it but flag it for review — only when
     * something actually disagreed (a different release, a different length).
     */
    public function needsReview(): bool
    {
        return $this->confidence === self::UNCERTAIN;
    }
}
