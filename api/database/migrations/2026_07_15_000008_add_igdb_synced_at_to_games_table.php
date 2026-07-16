<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // V50: last successful IGDB canonical fetch/match/refresh. Null =
            // never — drives the 24h freshness gate on platform sync (T51).
            $table->timestamp('igdb_synced_at')->nullable()->after('local_coop');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('igdb_synced_at');
        });
    }
};
