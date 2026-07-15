<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            // V29: filter (default) evaluates `filters` at read time (T7,
            // unchanged); manual has explicit collection_games membership.
            $table->string('type')->default('filter')->after('name');
        });

        Schema::table('collections', function (Blueprint $table) {
            $table->json('filters')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn('type');
        });

        Schema::table('collections', function (Blueprint $table) {
            $table->json('filters')->nullable(false)->change();
        });
    }
};
