<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owned_game_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owned_game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_achievement_def_id')->constrained()->cascadeOnDelete();
            $table->boolean('unlocked')->default(false);
            $table->timestamp('unlocked_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['owned_game_id', 'game_achievement_def_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owned_game_achievements');
    }
};
