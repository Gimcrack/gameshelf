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

    public function __construct(
        private readonly IgdbClient $client,
        private readonly IgdbGenreCatalog $genreCatalog,
    ) {
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
            $genreId = $this->genreCatalog->id($genreName);

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

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    private function hits(array $records): array
    {
        return IgdbHitMapper::map($records);
    }
}
