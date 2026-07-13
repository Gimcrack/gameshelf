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
        Schema::create('playtime_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owned_game_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('playtime_minutes');
            $table->timestamp('captured_at');

            $table->index(['owned_game_id', 'captured_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('playtime_snapshots');
    }
};
