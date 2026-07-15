<?php

namespace App\Services\Discover;

use App\Models\User;
use App\Services\Igdb\IgdbClient;
use Illuminate\Support\Facades\Cache;

/**
 * T19: upcoming-releases rail. Caller's top owned genres (by occurrence
 * across owned games) drive an IGDB catalogue query windowed to the next
 * 6 months (§C.discovery). Query results cache globally by resolved
 * genre-id set + day (V4) — genre ids are a global mapping, not per-user;
 * ownership/wishlist overlay applies per request (V20).
 */
class UpcomingReleases
{
    private const TTL_HOURS = 6;

    private const TOP_GENRE_LIMIT = 3;

    private const WINDOW_MONTHS = 6;

    public function __construct(
        private readonly IgdbClient $client,
        private readonly IgdbGenreCatalog $genreCatalog,
        private readonly OwnershipOverlay $overlay,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(User $user): array
    {
        $genreIds = $this->topGenreIds($user);

        // No genre signal yet (empty or ungenred library) → no personalized
        // rail rather than an unfiltered firehose of every upcoming game.
        if ($genreIds === []) {
            return [];
        }

        $from = now();
        $to = now()->addMonths(self::WINDOW_MONTHS);

        $records = Cache::remember(
            sprintf('igdb-discover:upcoming:%s:%s', implode(',', $genreIds), $from->toDateString()),
            now()->addHours(self::TTL_HOURS),
            fn () => $this->client->upcomingGames($genreIds, $from->timestamp, $to->timestamp),
        );

        return $this->overlay->apply($user, IgdbHitMapper::map($records));
    }

    /**
     * @return list<int>
     */
    private function topGenreIds(User $user): array
    {
        $genreNames = $user->ownedGames()
            ->with('game')
            ->get()
            ->unique('game_id')
            ->pluck('game.genres')
            ->flatMap(fn (?array $genres) => $genres ?? [])
            ->countBy()
            ->sortDesc()
            ->keys()
            ->take(self::TOP_GENRE_LIMIT);

        return $genreNames
            ->map(fn (string $name) => $this->genreCatalog->id($name))
            ->filter(fn (?int $id) => $id !== null)
            ->values()
            ->all();
    }
}
