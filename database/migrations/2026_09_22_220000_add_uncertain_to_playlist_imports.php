<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the matches an import made but is not certain of (S-324).
 *
 * Matching is graded now: a track whose title and artist agree while the album
 * or length does not is added to the playlist, because the library plainly
 * holds the song, but it is worth a glance. Keeping those here lets the import
 * page list them for confirmation without re-running the match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlist_imports', function (Blueprint $table): void {
            $table->json('uncertain')->nullable()->after('unmatched');
        });
    }

    public function down(): void
    {
        Schema::table('playlist_imports', function (Blueprint $table): void {
            $table->dropColumn('uncertain');
        });
    }
};
