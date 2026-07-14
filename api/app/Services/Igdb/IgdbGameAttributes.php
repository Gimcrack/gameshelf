<?php

namespace App\Services\Igdb;

use Illuminate\Support\Facades\Date;

/**
 * One IGDB record → `games` column mapping, shared by the sync-time
 * matcher and manual/wishlist adds so both write identical shapes (V7).
 */
class IgdbGameAttributes
{
    /**
     * @param  array<string, mixed>  $igdb
     * @return array<string, mixed>
     */
    public static function fromRecord(array $igdb): array
    {
        return [
            'igdb_id' => $igdb['id'],
            'title' => $igdb['name'],
            'cover_url' => $igdb['cover']['url'] ?? null,
            'genres' => array_map(
                fn (array $genre) => $genre['name'],
                $igdb['genres'] ?? [],
            ),
            'release_date' => isset($igdb['first_release_date'])
                ? Date::createFromTimestamp($igdb['first_release_date'])->toDateString()
                : null,
        ];
    }
}
