<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // V30: IGDB-only taxonomy, mirrors genres.
            $table->json('themes')->nullable()->after('genres');
            $table->json('keywords')->nullable()->after('themes');
            $table->json('game_modes')->nullable()->after('keywords');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['themes', 'keywords', 'game_modes']);
        });
    }
};
