<?php

namespace App\Models;

use App\Enums\GameStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * V6: user-owned metadata, fully decoupled from platform sync — re-syncs
 * never read or write this table.
 */
#[Fillable(['user_id', 'game_id', 'status', 'tags', 'notes', 'rating', 'hidden'])]
class UserGameMeta extends Model
{
    protected $table = 'user_game_meta';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GameStatus::class,
            'tags' => 'array',
            'hidden' => 'boolean',
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
