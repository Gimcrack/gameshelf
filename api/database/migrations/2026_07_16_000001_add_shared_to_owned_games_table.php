<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owned_games', function (Blueprint $table) {
            // T60/V58: true = ingested via a steam_family synthetic connection
            // (family member's shared library), mirrors free_to_play. Rows
            // always carry playtime_minutes null (V58 — not the caller's playtime).
            $table->boolean('shared')->default(false)->after('free_to_play');
        });
    }

    public function down(): void
    {
        Schema::table('owned_games', function (Blueprint $table) {
            $table->dropColumn('shared');
        });
    }
};
