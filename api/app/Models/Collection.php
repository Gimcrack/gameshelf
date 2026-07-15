<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * I.api: type=filter (default) is a saved /api/library filter preset;
 * type=manual is an explicit add/remove game membership list (V29). System
 * collections (unplayed, abandoned, quick wins) are computed, never stored
 * here.
 */
#[Fillable(['user_id', 'name', 'type', 'filters'])]
class Collection extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function games(): BelongsToMany
    {
        return $this->belongsToMany(Game::class, 'collection_games')->withPivot('added_at');
    }
}
