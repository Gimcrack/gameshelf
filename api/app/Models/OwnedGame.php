<?php

namespace App\Models;

use App\Enums\DeckStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'platform_connection_id',
    'game_id',
    'platform_game_id',
    'playtime_minutes',
    'last_played_at',
    'install_status',
    'added_at',
    'deck_status',
])]
class OwnedGame extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_played_at' => 'datetime',
            'added_at' => 'datetime',
            'deck_status' => DeckStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PlatformConnection::class, 'platform_connection_id');
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function playtimeSnapshots(): HasMany
    {
        return $this->hasMany(PlaytimeSnapshot::class);
    }
}
