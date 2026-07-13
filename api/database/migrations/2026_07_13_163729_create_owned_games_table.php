<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('owned_games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('platform_game_id');
            // Null = unknown (GOG), distinct from 0 = unplayed (V12).
            $table->unsignedInteger('playtime_minutes')->nullable();
            $table->timestamp('last_played_at')->nullable();
            $table->string('install_status')->nullable();
            $table->timestamp('added_at');
            $table->timestamps();

            // V10: re-sync upserts against this key — no duplicate rows.
            $table->unique(['platform_connection_id', 'platform_game_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('owned_games');
    }
};
