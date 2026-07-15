<?php

namespace App\Services\Discover;

use App\Services\Igdb\IgdbImageUrl;

/**
 * IGDB game records → §I discover hit shape (sans per-user flags, V20).
 * Shared by search/browse (DiscoverCatalog) and similar-games rails
 * (SimilarGames) — same upstream record shape, same target shape.
 */
class IgdbHitMapper
{
    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    public static function map(array $records): array
    {
        return array_values(array_map(
            fn (array $record) => [
                'igdb_id' => (int) $record['id'],
                'title' => $record['name'] ?? '',
                'cover_url' => IgdbImageUrl::resize($record['cover']['url'] ?? null),
                'genres' => array_map(
                    fn (array $genre) => $genre['name'],
                    $record['genres'] ?? [],
                ),
                'release_date' => isset($record['first_release_date'])
                    ? date('Y-m-d', (int) $record['first_release_date'])
                    : null,
                'rating' => isset($record['total_rating'])
                    ? (int) round($record['total_rating'])
                    : null,
            ],
            $records,
        ));
    }
}
