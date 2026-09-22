<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\PlaylistPorter\Services;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Services\ContentGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Matches an imported playlist track to a library item (S-310, S-324).
 *
 * Identifiers first — an ISRC, a MusicBrainz recording id, a Spotify id — which
 * are proof and end the search. Everything else is graded rather than decided:
 * candidates are gathered on the title, then scored on artist, album and
 * length, and the best one is returned with how much to trust it.
 *
 * **Why scoring, not filtering.** The first version required the album to be
 * equal and the length within three seconds. On a real 530-track import that
 * rejected 83 songs the library actually held: 56 because the playlist named
 * the original album while the library had a greatest-hits copy (or a remaster
 * ran a few seconds long), and 27 because a collaboration credit
 * ("Morgan Wallen, Florida Georgia Line") was compared against the primary
 * artist alone and could never be equal. A playlist wants the song, not a
 * particular pressing, so release and length now *corroborate* a match rather
 * than veto one, and a disagreement lowers confidence instead of discarding a
 * song the user owns.
 *
 * Every candidate query is routed through the ContentGate, so a track a profile
 * may not see is never matched into its playlist.
 */
class TrackMatcher
{
    /** Below this, a candidate is not offered at all. */
    private const MINIMUM_SCORE = 0.5;

    /** How far two recordings of the same song may differ, in milliseconds. */
    private const DURATION_TOLERANCE_MS = 3000;

    /** Beyond this the lengths disagree enough to doubt it is the same cut. */
    private const DURATION_LIMIT_MS = 15000;

    public function __construct(private ContentGate $gate) {}

    /**
     * The library item this track is, or null when nothing is plausible.
     *
     * Kept for callers that only want the item; `best()` carries the confidence.
     */
    public function match(ImportedTrack $track): ?MediaItem
    {
        return $this->best($track)?->item;
    }

    /**
     * The best candidate for this track, with how much to trust it.
     */
    public function best(ImportedTrack $track): ?TrackMatch
    {
        return $this->candidatesFor($track)->first();
    }

    /**
     * Every plausible library item for this track, best first.
     *
     * The review list uses this to offer a choice rather than a search box.
     *
     * @return Collection<int, TrackMatch>
     */
    public function candidatesFor(ImportedTrack $track, int $limit = 5): Collection
    {
        if ($track->isEmpty()) {
            return collect();
        }

        if ($exact = $this->byIdentifier($track)) {
            return collect([$exact]);
        }

        return $this->byTitleAndArtist($track)
            ->sortByDesc->score
            ->take($limit)
            ->values();
    }

    /**
     * A match on a recording identifier, tried in descending confidence. These
     * are proof: no scoring, and no review.
     */
    private function byIdentifier(ImportedTrack $track): ?TrackMatch
    {
        $identifiers = array_filter([
            'isrc' => $track->isrc,
            'musicbrainz_recording_id' => $track->musicbrainzRecordingId,
            'spotify_id' => $track->spotifyId,
        ], fn ($v) => filled($v));

        foreach ($identifiers as $column => $value) {
            $match = $this->candidates()
                ->whereHas('musicMetadata', fn (Builder $q) => $q->where($column, $value))
                ->orderBy('id')
                ->first();

            if ($match !== null) {
                return new TrackMatch(
                    item: $match,
                    confidence: TrackMatch::CERTAIN,
                    reason: match ($column) {
                        'isrc' => 'Same recording (ISRC).',
                        'musicbrainz_recording_id' => 'Same recording (MusicBrainz).',
                        default => 'Same recording (Spotify id).',
                    },
                    score: 1.0,
                );
            }
        }

        return null;
    }

    /**
     * Candidates sharing this track's title, scored on everything else known.
     *
     * @return Collection<int, TrackMatch>
     */
    private function byTitleAndArtist(ImportedTrack $track): Collection
    {
        $title = $this->normaliseTitle((string) $track->title);
        $artist = $this->normaliseArtist((string) $track->artist);

        if ($title === '' || $artist === '') {
            return collect();
        }

        return $this->candidatesByTitle($title)
            ->map(fn (MediaItem $item) => $this->score($item, $track, $title, $artist))
            ->filter()
            ->values();
    }

    /**
     * Library items whose title matches, comparing normalised forms so a
     * "(feat. …)" suffix or stray punctuation does not hide a song.
     *
     * The normalised comparison cannot be done in SQL without storing a
     * normalised column, so the database narrows the rows and the exact test
     * happens in PHP. The narrowing matches on a *word* of the title rather
     * than its first characters: normalising strips leading punctuation, so a
     * prefix taken from the normalised form ("theres no gettin over me")
     * never matches the stored title ("(There's) No Gettin' Over Me"). That
     * quietly lost every song whose title opens with a bracket or a number.
     *
     * @return Collection<int, MediaItem>
     */
    private function candidatesByTitle(string $title): Collection
    {
        // The longest *plain* word is the most selective. Words carrying an
        // apostrophe are skipped: normalising folds a curly "’" to a straight
        // "'", but the LIKE runs against the stored title, where the original
        // character survives — so anchoring on "whiskey'd" matches nothing.
        $words = array_filter(
            explode(' ', $title),
            fn ($w) => mb_strlen($w) >= 3 && ! str_contains($w, "'"),
        );
        usort($words, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $anchor = $words[0] ?? null;

        return $this->candidates()
            ->with('musicMetadata')
            ->where(function (Builder $q) use ($title, $anchor): void {
                $q->whereRaw('LOWER(TRIM(title)) = ?', [$title]);

                // With no punctuation-free word to anchor on (a title like
                // "S.O.B."), fall back to the first letter rather than giving
                // up: a wider scan still ends at the exact test below.
                $q->orWhere('title', 'like', $anchor !== null
                    ? '%'.$anchor.'%'
                    : mb_substr($title, 0, 1).'%');
            })
            ->limit(400)
            ->get()
            ->filter(fn (MediaItem $item) => $this->normaliseTitle((string) $item->title) === $title);
    }

    /**
     * Grade one candidate against the imported track.
     *
     * The artist must agree for a candidate to stand at all — a shared title is
     * far too common on its own. Album and length then raise or lower the score
     * rather than decide it.
     */
    private function score(MediaItem $item, ImportedTrack $track, string $title, string $artist): ?TrackMatch
    {
        $metadata = $item->musicMetadata;

        if ($metadata === null) {
            return null;
        }

        if (! $this->artistAgrees($metadata, $artist)) {
            return null;
        }

        $score = 0.8;
        $doubts = [];

        // The release. Equal is corroboration; different is common and mild —
        // the same song on a greatest-hits or a soundtrack is still the song.
        if (filled($track->album) && filled($metadata->album)) {
            if ($this->normaliseTitle($track->album) === $this->normaliseTitle((string) $metadata->album)) {
                $score += 0.15;
            } else {
                $score -= 0.1;
                $doubts[] = 'a different release ('.$metadata->album.')';
            }
        }

        // The length. Close is corroboration; far apart suggests a different
        // cut — a live version, an edit — which is worth a person's glance.
        if ($track->durationMs !== null && $metadata->duration_ms !== null) {
            $difference = abs($track->durationMs - (int) $metadata->duration_ms);

            if ($difference <= self::DURATION_TOLERANCE_MS) {
                $score += 0.15;
            } elseif ($difference >= self::DURATION_LIMIT_MS) {
                $score -= 0.25;
                $doubts[] = 'a length '.round($difference / 1000).'s apart';
            } else {
                $score -= 0.05;
                $doubts[] = 'a slightly different length';
            }
        }

        $score = max(0.0, min(1.0, $score));

        if ($score < self::MINIMUM_SCORE) {
            return null;
        }

        // Trust rests on nothing having disagreed, not on how much corroborating
        // detail happened to be present. A track carrying no album or length is
        // not doubtful — there was simply nothing more to check — so it must not
        // be flagged alongside the ones where a release or a length actually
        // contradicted the match.
        $certain = $doubts === [];

        return new TrackMatch(
            item: $item,
            confidence: $certain ? TrackMatch::LIKELY : TrackMatch::UNCERTAIN,
            reason: $doubts === []
                ? 'Title and artist match.'
                : 'Title and artist match, but '.$this->sentence($doubts).'.',
            score: $score,
        );
    }

    /**
     * Whether a library item is credited to the imported track's artist.
     *
     * Checked against the full credit *and* the primary artist, either way
     * round. The first version coalesced to `primary_artist` when it was set,
     * so a track credited "Morgan Wallen, Florida Georgia Line" in both the
     * playlist and the library still failed, because the full credit was
     * compared against "Morgan Wallen" alone. Collaborations are common enough
     * on a playlist that this silently lost 27 songs out of 530.
     */
    private function artistAgrees(mixed $metadata, string $artist): bool
    {
        $credits = array_filter([
            $this->normaliseArtist((string) ($metadata->artist ?? '')),
            $this->normaliseArtist((string) ($metadata->primary_artist ?? '')),
        ]);

        foreach ($credits as $credit) {
            if ($credit === $artist) {
                return true;
            }

            // One side naming only the lead, the other the whole collaboration.
            if ($this->leadArtist($credit) === $this->leadArtist($artist)) {
                return true;
            }
        }

        return false;
    }

    /** The first credited artist, for comparing a lead against a collaboration. */
    private function leadArtist(string $credit): string
    {
        $parts = preg_split('/\s*(?:,|&|\band\b|\bwith\b|\bfeat\.?\b|\bfeaturing\b|\bx\b)\s*/u', $credit) ?: [];

        return trim($parts[0] ?? $credit);
    }

    /**
     * A title reduced to what identifies the song: lowercased, with a trailing
     * "(feat. …)" or bracketed qualifier and stray punctuation removed, so
     * "Up Down (feat. Florida Georgia Line)" and "Up Down" are one song.
     */
    private function normaliseTitle(string $value): string
    {
        $value = mb_strtolower(trim($value));

        // A parenthesised feature credit is not part of the title.
        $value = preg_replace('/[\(\[]\s*(feat\.?|featuring|with)\s[^\)\]]*[\)\]]/u', '', $value) ?? $value;

        // Nor is a trailing remaster/version note.
        $value = preg_replace('/[\(\[][^\)\]]*\b(remaster(ed)?|version|edit|mix|mono|stereo)\b[^\)\]]*[\)\]]/u', '', $value) ?? $value;

        // Curly quotes and dashes vary by tagger; fold them away.
        $value = strtr($value, ['’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-']);
        $value = preg_replace('/[^\p{L}\p{N}\s\']+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /** An artist credit reduced the same way, so punctuation cannot divide it. */
    private function normaliseArtist(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['’' => "'", '‘' => "'", '–' => '-', '—' => '-']);
        $value = preg_replace('/\bfeat\.?\b|\bfeaturing\b/u', ',', $value) ?? $value;
        $value = preg_replace('/[^\p{L}\p{N}\s,&\']+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /** "a and b", "a, b and c" — for readable reasons. */
    private function sentence(array $parts): string
    {
        if (count($parts) === 1) {
            return $parts[0];
        }

        $last = array_pop($parts);

        return implode(', ', $parts).' and '.$last;
    }

    /**
     * The base set of library items a track may match: music the current profile
     * is allowed to see, and not itself a flagged duplicate.
     */
    private function candidates(): Builder
    {
        return $this->gate->apply(MediaItem::query())
            ->where('type', MediaItemType::Music)
            ->whereNull('duplicate_of_id');
    }
}
