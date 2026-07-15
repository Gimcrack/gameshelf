<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_game_meta', function (Blueprint $table) {
            // T25/V28: default false — a missing meta row (untouched game)
            // is never hidden.
            $table->boolean('hidden')->default(false)->after('rating');
        });
    }

    public function down(): void
    {
        Schema::table('user_game_meta', function (Blueprint $table) {
            $table->dropColumn('hidden');
        });
    }
};
