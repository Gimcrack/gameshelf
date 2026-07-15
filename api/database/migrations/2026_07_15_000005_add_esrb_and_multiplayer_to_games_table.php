<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // T27/V33: nullable — unrated | non-ESRB-market games stay null.
            $table->string('esrb_rating')->nullable()->after('time_to_beat_minutes');
            // T27/V32: null = not yet fetched/best-effort miss, distinct from false.
            $table->boolean('multiplayer')->nullable()->after('esrb_rating');
            $table->boolean('coop')->nullable()->after('multiplayer');
            $table->boolean('local_multiplayer')->nullable()->after('coop');
            $table->boolean('local_coop')->nullable()->after('local_multiplayer');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['esrb_rating', 'multiplayer', 'coop', 'local_multiplayer', 'local_coop']);
        });
    }
};
