<?php

namespace App\Services\Discover;

use App\Models\OwnedGame;
use App\Models\User;
use App\Models\UserGameMeta;
use App\Services\Igdb\IgdbClient;
use Illuminate\Support\Facades\Cache;

/**
 * T16: "because you played X" rails. Seeds = caller's top-rated/top-playtime
 * owned games with an igdb_id (§I). Each seed's IGDB similar_games list
 * caches globally by igdb_id (V4, mirrors DiscoverCatalog) — ownership and
 * wishlist overlay is computed fresh per request (V20).
 */
class SimilarGames
{
    private const SEED_LIMIT = 4;

    private const TTL_HOURS = 6;

    public function __construct(
        private readonly IgdbClient $client,
        private readonly OwnershipOverlay $overlay,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function railsFor(User $user): array
    {
        return array_values(array_filter(array_map(
            fn (array $seed) => $this->rail($user, $seed),
            $this->seeds($user),
        )));
    }

    /**
     * Top owned games by declared rating first (explicit signal), playtime
     * as tiebreak/fallback for games the user never rated.
     *
     * @return list<array{game_id: int, igdb_id: int, title: string, cover_url: ?string}>
     */
    private function seeds(User $user): array
    {
        $rows = OwnedGame::query()
            ->where('owned_games.user_id', $user->id)
            ->join('games', 'games.id', '=', 'owned_games.game_id')
            ->whereNotNull('games.igdb_id')
            ->selectRaw(
                'games.id as game_id, games.igdb_id, games.title, games.cover_url, '
                .'SUM(owned_games.playtime_minutes) as total_playtime',
            )
            ->groupBy('games.id', 'games.igdb_id', 'games.title', 'games.cover_url')
            ->get();

        $ratings = UserGameMeta::where('user_id', $user->id)->pluck('rating', 'game_id');

        return $rows
            ->sort(function ($a, $b) use ($ratings) {
                $ratingCompare = ($ratings[$b->game_id] ?? -1) <=> ($ratings[$a->game_id] ?? -1);

                return $ratingCompare !== 0
                    ? $ratingCompare
                    : ($b->total_playtime ?? -1) <=> ($a->total_playtime ?? -1);
            })
            ->take(self::SEED_LIMIT)
            ->map(fn ($row) => [
                'game_id' => (int) $row->game_id,
                'igdb_id' => (int) $row->igdb_id,
                'title' => $row->title,
                'cover_url' => $row->cover_url,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{game_id: int, igdb_id: int, title: string, cover_url: ?string}  $seed
     * @return array<string, mixed>|null
     */
    private function rail(User $user, array $seed): ?array
    {
        $records = Cache::remember(
            'igdb-discover:similar:'.$seed['igdb_id'],
            now()->addHours(self::TTL_HOURS),
            fn () => $this->client->similarGames($seed['igdb_id']),
        );

        $hits = array_values(array_filter(
            IgdbHitMapper::map($records),
            fn (array $hit) => $hit['igdb_id'] !== $seed['igdb_id'],
        ));

        // No similar-games data for this seed on IGDB — skip an empty rail.
        if ($hits === []) {
            return null;
        }

        return [
            'seed' => [
                'id' => $seed['game_id'],
                'igdb_id' => $seed['igdb_id'],
                'title' => $seed['title'],
                'cover_url' => $seed['cover_url'],
            ],
            'similar' => $this->overlay->apply($user, $hits),
        ];
    }
}
