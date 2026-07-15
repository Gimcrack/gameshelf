<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owned_games', function (Blueprint $table) {
            // T37/V41: true = Steam reported this appid only when
            // include_played_free_games=1 was set. Rows only exist for F2P
            // titles with playtime > 0 — zero-playtime F2P is never ingested.
            $table->boolean('free_to_play')->default(false)->after('deck_status');
        });
    }

    public function down(): void
    {
        Schema::table('owned_games', function (Blueprint $table) {
            $table->dropColumn('free_to_play');
        });
    }
};
