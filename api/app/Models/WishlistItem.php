<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * V21: a wish, not ownership — never counted in library, stats, backlog.
 */
#[Fillable([
    'user_id', 'game_id', 'added_at', 'origin', 'steam_present',
    'gog_present', 'gog_product_id', 'steam_appid', 'suppressed_at', 'synced_at',
])]
class WishlistItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'added_at' => 'datetime',
            'steam_present' => 'boolean',
            'gog_present' => 'boolean',
            'suppressed_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
