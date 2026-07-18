<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // T80/V76: null = not yet fetched, distinct from false (mirrors multiplayer family).
            $table->boolean('vr_supported')->nullable()->after('local_coop');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('vr_supported');
        });
    }
};
