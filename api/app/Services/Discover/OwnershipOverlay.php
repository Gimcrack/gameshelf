<?php

namespace App\Services\Discover;

use App\Models\User;
use App\Models\WishlistItem;

/**
 * V20: per-request ownership flags on globally cached discovery hits.
 * Never cached — stale per-user overlay is the failure mode V20 forbids.
 */
class OwnershipOverlay
{
    /**
     * @param  list<array<string, mixed>>  $hits
     * @return list<array<string, mixed>>
     */
    public function apply(User $user, array $hits): array
    {
        $ownedIgdbIds = $user->ownedGames()
            ->join('games', 'games.id', '=', 'owned_games.game_id')
            ->whereNotNull('games.igdb_id')
            ->pluck('games.igdb_id')
            ->flip();

        $wishedIgdbIds = WishlistItem::where('user_id', $user->id)
            ->whereNull('suppressed_at')
            ->join('games', 'games.id', '=', 'wishlist_items.game_id')
            ->whereNotNull('games.igdb_id')
            ->pluck('games.igdb_id')
            ->flip();

        return array_map(
            fn (array $hit) => [
                ...$hit,
                'in_library' => $ownedIgdbIds->has($hit['igdb_id']),
                'in_wishlist' => $wishedIgdbIds->has($hit['igdb_id']),
            ],
            $hits,
        );
    }
}
