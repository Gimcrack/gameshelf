<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('steam_id');
            $table->string('persona_name');
            $table->string('avatar_url');
            // V58: synthetic steam_family connection this member syncs through.
            $table->foreignId('platform_connection_id')->constrained('platform_connections')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'steam_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_members');
    }
};
