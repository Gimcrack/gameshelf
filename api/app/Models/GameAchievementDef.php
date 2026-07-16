<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'platform',
    'platform_game_id',
    'api_name',
    'name',
    'description',
    'icon_url',
    'points',
    'fetched_at',
])]
class GameAchievementDef extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'fetched_at' => 'datetime',
        ];
    }
}
