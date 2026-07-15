<?php

namespace App\Services\Discover;

use App\Services\Igdb\IgdbClient;
use Illuminate\Support\Facades\Cache;

/**
 * Genre name → IGDB genre id lookup, shared by browse (single genre) and
 * upcoming (caller's top genres). Cached globally (V4) — the genre
 * taxonomy is the same for every caller.
 */
class IgdbGenreCatalog
{
    private const TTL_DAYS = 7;

    public function __construct(private readonly IgdbClient $client)
    {
    }

    public function id(string $name): ?int
    {
        foreach ($this->all() as $genre) {
            if (strcasecmp($genre['name'] ?? '', trim($name)) === 0) {
                return (int) $genre['id'];
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return Cache::remember(
            'igdb-discover:genres',
            now()->addDays(self::TTL_DAYS),
            fn () => $this->client->genres(),
        );
    }
}
