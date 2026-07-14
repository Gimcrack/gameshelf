<?php

namespace App\Services\Discover;

use App\Services\Igdb\IgdbClient;
use Illuminate\Support\Facades\Cache;

/**
 * IGDB catalogue proxy for /api/discover (§C.discovery). Payloads cache
 * globally — no user in any key — per V20; ownership overlay happens later,
 * per request.
 */
class DiscoverCatalog
{
    private const RESULTS_TTL_HOURS = 6;

    private const GENRES_TTL_DAYS = 7;

    public function __construct(private readonly IgdbClient $client)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $q): array
    {
        $normalized = mb_strtolower(trim($q));

        return Cache::remember(
            'igdb-discover:search:'.md5($normalized),
            now()->addHours(self::RESULTS_TTL_HOURS),
            fn () => $this->hits($this->client->searchGames($normalized)),
        );
    }

    /**
     * Unknown genre name → empty page (nothing on IGDB matches it).
     *
     * @return list<array<string, mixed>>
     */
    public function browse(?string $genreName, string $sort, int $page): array
    {
        $genreId = null;

        if ($genreName !== null && trim($genreName) !== '') {
            $genreId = $this->genreId($genreName);

            if ($genreId === null) {
                return [];
            }
        }

        return Cache::remember(
            sprintf('igdb-discover:browse:%s:%s:%d', $genreId ?? 'all', $sort, $page),
            now()->addHours(self::RESULTS_TTL_HOURS),
            fn () => $this->hits($this->client->browseGames($genreId, $sort, $page)),
        );
    }

    private function genreId(string $name): ?int
    {
        $genres = Cache::remember(
            'igdb-discover:genres',
            now()->addDays(self::GENRES_TTL_DAYS),
            fn () => $this->client->genres(),
        );

        foreach ($genres as $genre) {
            if (strcasecmp($genre['name'] ?? '', trim($name)) === 0) {
                return (int) $genre['id'];
            }
        }

        return null;
    }

    /**
     * IGDB records → §I hit shape, sans per-user flags (V20).
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    private function hits(array $records): array
    {
        return array_values(array_map(
            fn (array $record) => [
                'igdb_id' => (int) $record['id'],
                'title' => $record['name'] ?? '',
                'cover_url' => $record['cover']['url'] ?? null,
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
