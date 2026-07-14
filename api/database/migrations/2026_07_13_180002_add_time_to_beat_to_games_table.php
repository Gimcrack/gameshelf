<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // §C: quick wins are conditional on IGDB time-to-beat; null means
            // no data and the game is excluded from that collection.
            $table->unsignedInteger('time_to_beat_minutes')->nullable()->after('release_date');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('time_to_beat_minutes');
        });
    }
};
