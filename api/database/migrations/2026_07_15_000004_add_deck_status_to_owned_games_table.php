<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owned_games', function (Blueprint $table) {
            // T26/V31: null = never successfully checked, distinct from
            // Valve's own "unknown" category (stored as the 'unknown' string).
            // Steam-only — gog/manual rows never attempt the fetch, stay null.
            $table->enum('deck_status', ['unknown', 'unsupported', 'playable', 'verified'])
                ->nullable()
                ->after('playtime_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('owned_games', function (Blueprint $table) {
            $table->dropColumn('deck_status');
        });
    }
};
