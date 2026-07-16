<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'owned_game_id',
    'game_achievement_def_id',
    'unlocked',
    'unlocked_at',
    'synced_at',
])]
class OwnedGameAchievement extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unlocked' => 'boolean',
            'unlocked_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function ownedGame(): BelongsTo
    {
        return $this->belongsTo(OwnedGame::class);
    }

    public function def(): BelongsTo
    {
        return $this->belongsTo(GameAchievementDef::class, 'game_achievement_def_id');
    }
}
