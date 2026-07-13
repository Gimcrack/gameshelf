<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['owned_game_id', 'playtime_minutes', 'captured_at'])]
class PlaytimeSnapshot extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
        ];
    }

    public function ownedGame(): BelongsTo
    {
        return $this->belongsTo(OwnedGame::class);
    }
}
