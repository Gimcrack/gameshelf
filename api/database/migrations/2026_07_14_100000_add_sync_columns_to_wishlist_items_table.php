<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishlist_items', function (Blueprint $table) {
            // V22 sync state: where the wish was first seen, which platforms
            // currently list it, and the tombstone that stops re-import
            // after a local delete.
            $table->string('origin')->default('local')->after('added_at');
            $table->boolean('steam_present')->default(false)->after('origin');
            $table->boolean('gog_present')->default(false)->after('steam_present');
            $table->string('gog_product_id')->nullable()->after('gog_present');
            $table->timestamp('suppressed_at')->nullable()->after('gog_product_id');
            $table->timestamp('synced_at')->nullable()->after('suppressed_at');
        });
    }

    public function down(): void
    {
        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->dropColumn([
                'origin', 'steam_present', 'gog_present',
                'gog_product_id', 'suppressed_at', 'synced_at',
            ]);
        });
    }
};
