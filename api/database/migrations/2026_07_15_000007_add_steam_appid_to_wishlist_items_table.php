<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishlist_items', function (Blueprint $table) {
            // V49: raw Steam appid for resolution-independent absence keying
            // (mirrors gog_product_id). Nullable — pre-T49 rows backfill NULL
            // and are kept until a post-T49 sync populates the column.
            $table->string('steam_appid')->nullable()->after('gog_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->dropColumn('steam_appid');
        });
    }
};
