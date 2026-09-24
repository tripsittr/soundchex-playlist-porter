<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\PlaylistPorter\Services;

use App\Models\Collection;
use Illuminate\Support\Facades\Log;
use SoundChex\PlaylistPorter\Models\PlaylistImport;
use SoundChex\PlaylistPorter\Services\Parsers\CsvParser;
use SoundChex\PlaylistPorter\Services\Parsers\M3uParser;
use SoundChex\PlaylistPorter\Services\Parsers\PlaylistFileParser;
use SoundChex\PlaylistPorter\Services\Parsers\XspfParser;

/**
 * Ports a playlist into the library (S-310).
 *
 * Parses a source into normalised tracks, matches each to a library item, builds
 * a playlist from the ones that matched, and records the rest as unmatched for
 * the user to resolve. The heavy lifting the clients drive: every platform only
 * has to hand this a file (or, later, a connected source) and show the result.
 */
class PlaylistImportService
{
    public function __construct(private TrackMatcher $matcher) {}

    /**
     * The file parsers, most specific first — XSPF and CSV are recognised by
     * extension and shape before the permissive M3U fallback.
     *
     * @return array<int, PlaylistFileParser>
     */
    private function fileParsers(): array
    {
        return [
            app(XspfParser::class),
            app(CsvParser::class),
            app(M3uParser::class),
        ];
    }

    /**
     * Reads a playlist file into a name and ordered tracks, or throws when no
     * parser recognises it.
     *
     * @return array{name: ?string, tracks: array<int, ImportedTrack>, format: string}
     */
    public function parseFile(string $contents, string $extension): array
    {
        foreach ($this->fileParsers() as $parser) {
            if ($parser->supports($extension, $contents)) {
                return [
                    'name' => $parser->name($contents),
                    'tracks' => $parser->parse($contents),
                    'format' => $this->formatOf($parser),
                ];
            }
        }

        throw new \RuntimeException('Unrecognised playlist file. Supported: M3U, CSV, XSPF.');
    }

    /**
     * A playlist this user already has under the name an import would use.
     *
     * Importing the same playlist twice is the normal case, not an edge one —
     * a service playlist changes and you bring it in again. Without this the
     * second import silently made a second playlist with the same name, and
     * nothing told anyone which was which (S-325).
     */
    public function existingPlaylist(int $userId, string $name): ?Collection
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        return Collection::query()
            ->where('user_id', $userId)
            // Names are compared as the user reads them: "Road Trip" and
            // "road trip" are the same playlist to a person.
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])
            ->orderBy('id')
            ->first();
    }

    /**
     * Runs a prepared import: matches its tracks, creates the playlist, and
     * records the outcome. The tracks are passed in (already parsed) so this is
     * the same whether they came from a file or, later, a service.
     *
     * `$mergeInto` adds to a playlist the user already has instead of making
     * another with the same name. Tracks it already holds are left alone —
     * `syncWithoutDetaching` keeps one row per item — so merging the same
     * import twice is a no-op rather than a doubling (S-325).
     *
     * @param  array<int, ImportedTrack>  $tracks
     */
    public function run(
        PlaylistImport $import,
        array $tracks,
        string $playlistName,
        ?Collection $mergeInto = null,
    ): void {
        $import->update([
            'status' => PlaylistImport::STATUS_PROCESSING,
            'total_tracks' => count($tracks),
        ]);

        try {
            $collection = $mergeInto ?? Collection::create([
                'user_id' => $import->user_id,
                'name' => $playlistName !== '' ? $playlistName : 'Imported playlist',
                'description' => 'Ported from '.($import->source_format ?? $import->source).'.',
            ]);

            $matched = 0;
            $unmatched = [];
            $uncertain = [];
            // Append when merging: starting at zero again would interleave
            // the new tracks through the playlist the user already had.
            $sortOrder = $mergeInto === null
                ? 0
                : ((int) $collection->mediaItems()->max('sort_order') + 1);

            foreach ($tracks as $track) {
                $match = $this->matcher->best($track);

                if ($match === null) {
                    // Nothing was confident enough to attach, but the near
                    // misses are worth offering rather than making the user
                    // search from scratch.
                    $unmatched[] = $track->toArray() + [
                        'candidates' => $this->candidateData($track),
                    ];

                    continue;
                }

                // Attach in playlist order, never detaching what is already there,
                // and skipping a track the playlist already holds (a playlist can
                // list the same song twice; the library item is attached once).
                $collection->mediaItems()->syncWithoutDetaching([
                    $match->item->id => ['sort_order' => $sortOrder++],
                ]);
                $matched++;

                // Attached, but the release or the length disagreed. Recorded so
                // the import page can ask about it without matching again.
                if ($match->needsReview()) {
                    $uncertain[] = $track->toArray() + [
                        'media_item_id' => $match->item->id,
                        'matched_title' => $match->item->title,
                        'reason' => $match->reason,
                        'score' => round($match->score, 2),
                    ];
                }
            }

            $import->update([
                'status' => PlaylistImport::STATUS_COMPLETE,
                'matched_tracks' => $matched,
                'unmatched' => $unmatched,
                'uncertain' => $uncertain,
                'collection_id' => $collection->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('playlist-import:failed', ['import' => $import->id, 'error' => $e->getMessage()]);
            $import->update([
                'status' => PlaylistImport::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The library items worth offering for a track nothing matched, best first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function candidateData(ImportedTrack $track): array
    {
        return $this->matcher->candidatesFor($track, 3)
            ->map(fn (TrackMatch $c) => [
                'media_item_id' => $c->item->id,
                'title' => $c->item->title,
                'artist' => $c->item->musicMetadata?->artist,
                'album' => $c->item->musicMetadata?->album,
                'reason' => $c->reason,
                'score' => round($c->score, 2),
            ])
            ->all();
    }

    private function formatOf(PlaylistFileParser $parser): string
    {
        return match ($parser::class) {
            XspfParser::class => 'xspf',
            CsvParser::class => 'csv',
            M3uParser::class => 'm3u',
            default => 'file',
        };
    }
}
