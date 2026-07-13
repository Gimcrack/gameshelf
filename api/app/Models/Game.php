<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['igdb_id', 'title', 'cover_url', 'genres', 'release_date'])]
class Game extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'genres' => 'array',
            'release_date' => 'date',
        ];
    }

    public function ownedGames(): HasMany
    {
        return $this->hasMany(OwnedGame::class);
    }
}
