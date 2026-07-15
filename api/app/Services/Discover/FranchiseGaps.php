<?php

namespace App\Services\Discover;

use App\Models\User;
use App\Services\Igdb\IgdbClient;
use App\Services\Igdb\IgdbImageUrl;
use Illuminate\Support\Facades\Cache;

/**
 * T18: "complete the series" rails. For every owned game with an igdb_id,
 * looks up its IGDB franchises and each franchise's full game list — one
 * nested-field call per owned game (V4: cached globally by igdb_id, like
 * SimilarGames). Franchises the caller already owns in full are dropped
 * (nothing to complete). Edition/remaster noise accepted v1 (§C.discovery)
 * — no dedup beyond IGDB's own game ids.
 */
class FranchiseGaps
{
    private const TTL_HOURS = 6;

    public function __construct(
        private readonly IgdbClient $client,
        private readonly OwnershipOverlay $overlay,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(User $user): array
    {
        $ownedIgdbIds = $user->ownedGames()
            ->join('games', 'games.id', '=', 'owned_games.game_id')
            ->whereNotNull('games.igdb_id')
            ->pluck('games.igdb_id')
            ->unique()
            ->values();

        $franchises = $this->collectFranchises($ownedIgdbIds->all());
        $ownedSet = $ownedIgdbIds->flip();

        $rails = [];

        foreach ($franchises as $franchise) {
            $rail = $this->rail($user, $franchise, $ownedSet);

            if ($rail !== null) {
                $rails[] = $rail;
            }
        }

        usort($rails, fn (array $a, array $b) => strcasecmp($a['franchise'], $b['franchise']));

        return array_values($rails);
    }

    /**
     * @param  list<int>  $ownedIgdbIds
     * @return array<int, array{name: string, games: array<int, array<string, mixed>>}>
     */
    private function collectFranchises(array $ownedIgdbIds): array
    {
        $franchises = [];

        foreach ($ownedIgdbIds as $seedIgdbId) {
            $records = Cache::remember(
                'igdb-discover:franchises:'.$seedIgdbId,
                now()->addHours(self::TTL_HOURS),
                fn () => $this->client->franchisesFor($seedIgdbId),
            );

            foreach ($records as $franchise) {
                $franchiseId = $franchise['id'] ?? null;

                if ($franchiseId === null) {
                    continue;
                }

                $franchises[$franchiseId] ??= ['name' => $franchise['name'] ?? '', 'games' => []];

                foreach ($franchise['games'] ?? [] as $game) {
                    $franchises[$franchiseId]['games'][$game['id']] = $game;
                }
            }
        }

        return $franchises;
    }

    /**
     * @param  array{name: string, games: array<int, array<string, mixed>>}  $franchise
     * @param  \Illuminate\Support\Collection<int, int>  $ownedSet
     * @return array<string, mixed>|null
     */
    private function rail(User $user, array $franchise, $ownedSet): ?array
    {
        $ownedRecords = [];
        $missingRecords = [];

        foreach ($franchise['games'] as $game) {
            if ($ownedSet->has($game['id'])) {
                $ownedRecords[] = $game;
            } else {
                $missingRecords[] = $game;
            }
        }

        // Nothing left to complete.
        if ($missingRecords === []) {
            return null;
        }

        return [
            'franchise' => $franchise['name'],
            'owned' => array_values(array_map(fn (array $g) => [
                'igdb_id' => (int) $g['id'],
                'title' => $g['name'] ?? '',
                'cover_url' => IgdbImageUrl::resize($g['cover']['url'] ?? null),
            ], $ownedRecords)),
            'missing' => $this->overlay->apply($user, IgdbHitMapper::map($missingRecords)),
        ];
    }
}
