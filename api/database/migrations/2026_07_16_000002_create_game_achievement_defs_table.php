<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_achievement_defs', function (Blueprint $table) {
            $table->id();
            // V63: keyed per (platform, platform_game_id) - not per canonical
            // `games` row. The same real-world game has an independent
            // achievement list per platform.
            $table->string('platform');
            $table->string('platform_game_id');
            $table->string('api_name');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('icon_url')->nullable();
            // Xbox gamerscore; Steam has no points concept, stays null.
            $table->unsignedInteger('points')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'platform_game_id', 'api_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_achievement_defs');
    }
};
