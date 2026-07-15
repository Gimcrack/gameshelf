<?php

namespace App\Models;

use App\Services\Igdb\IgdbImageUrl;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'igdb_id', 'title', 'cover_url', 'genres', 'themes', 'keywords', 'game_modes',
    'release_date', 'time_to_beat_minutes',
])]
class Game extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'genres' => 'array',
            'themes' => 'array',
            'keywords' => 'array',
            'game_modes' => 'array',
            'release_date' => 'date',
        ];
    }

    /**
     * Upsized on every read so already-synced games benefit without a
     * backfill — the stored value keeps whatever size IGDB gave at sync time.
     */
    protected function coverUrl(): Attribute
    {
        return Attribute::get(fn (?string $value) => IgdbImageUrl::resize($value));
    }

    public function ownedGames(): HasMany
    {
        return $this->hasMany(OwnedGame::class);
    }
}
