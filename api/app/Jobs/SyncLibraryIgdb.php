<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\UserGameMeta;
use App\Models\WishlistItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/**
 * T31/V38 + T32/V39: bulk "sync all IGDB" orchestrator. Makes no IGDB
 * calls itself (B8: the monolithic version serial-looped the whole
 * library and blew the queue timeout) — it only fans out bounded child
 * jobs: one MatchConnectionIgdb per connection, one MatchGameIgdb per
 * provisional wishlist/meta-orphan game, one RefreshGameIgdb per
 * already-matched game.
 *
 * T50/V48 (B15): both halves cover the whole library union (owned ∪
 * wishlist_items ∪ meta-orphans, V42), not ownedGames() only — wishlist-only
 * and meta-orphan games were previously never matched nor refreshed.
 */
class SyncLibraryIgdb implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $userId)
    {
    }

    public function handle(): void
    {
        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        // Snapshot before match jobs run — a game matched during this same
        // pass gets full canonical data from that fetch already, so also
        // dispatching a refresh for it would be pure duplicate IGDB volume.
        $alreadyMatchedIds = $this->matchedGameIds($user);

        // Match half. Owned provisionals ride their connection's match job
        // (keyed by platform_game_id, V4 cache path). Wishlist-only and
        // meta-orphan provisionals have no owned row / cache key → a per-game
        // title-search match job each (V48/V34).
        foreach ($user->platformConnections as $connection) {
            MatchConnectionIgdb::dispatch($connection->id);
        }

        foreach ($this->unionProvisionalGameIds($user) as $gameId) {
            MatchGameIgdb::dispatch($user->id, $gameId);
        }

        foreach ($alreadyMatchedIds as $gameId) {
            RefreshGameIgdb::dispatch($gameId);
        }
    }

    /**
     * V48: already-matched games across the whole union (owned ∪ wishlist ∪
     * meta-orphans, V42) — refresh half, not ownedGames() only.
     *
     * @return Collection<int, int>
     */
    private function matchedGameIds(User $user): Collection
    {
        $owned = $user->ownedGames()
            ->join('games', 'games.id', '=', 'owned_games.game_id')
            ->whereNotNull('games.igdb_id')
            ->pluck('games.id');

        $wishlist = WishlistItem::where('user_id', $user->id)
            ->whereNull('suppressed_at')
            ->whereHas('game', fn ($query) => $query->whereNotNull('igdb_id'))
            ->pluck('game_id');

        $meta = UserGameMeta::where('user_id', $user->id)
            ->whereHas('game', fn ($query) => $query->whereNotNull('igdb_id'))
            ->pluck('game_id');

        return $owned->concat($wishlist)->concat($meta)->unique()->values();
    }

    /**
     * V48: provisional (igdb_id null) games referenced by the caller's
     * wishlist or meta but NOT owned — owned provisionals are matched via
     * their connection (V4 cache path), so they're excluded here to avoid a
     * duplicate title-search dispatch.
     *
     * @return Collection<int, int>
     */
    private function unionProvisionalGameIds(User $user): Collection
    {
        $ownedGameIds = $user->ownedGames()->pluck('game_id');

        $wishlist = WishlistItem::where('user_id', $user->id)
            ->whereNull('suppressed_at')
            ->whereHas('game', fn ($query) => $query->whereNull('igdb_id'))
            ->pluck('game_id');

        $meta = UserGameMeta::where('user_id', $user->id)
            ->whereHas('game', fn ($query) => $query->whereNull('igdb_id'))
            ->pluck('game_id');

        return $wishlist->concat($meta)->unique()->diff($ownedGameIds)->values();
    }
}
