<?php

namespace App\Services\Library;

use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\User;
use App\Models\UserGameMeta;
use App\Models\WishlistItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * T29/V34: manual "fix match" — reuses V7's canonical-row dedupe exactly
 * (mirrors GameMatcher::canonicalize). T47/V34: operates on the whole library
 * union (owned_games ∪ wishlist_items ∪ meta-orphans, V42), not owned-only —
 * repoints whichever of the caller's reference rows exist for the game to the
 * chosen canonical row. Available regardless of current match state: a
 * provisional miss or an already-matched-but-wrong game, both correctable.
 */
class GameRematch
{
    public function __construct(private readonly GameFromIgdb $games)
    {
    }

    /**
     * Null return means the igdb_id is unknown to IGDB.
     */
    public function apply(User $user, Game $game, int $igdbId): ?Game
    {
        $target = $this->games->findOrCreate($igdbId);

        if ($target === null) {
            return null;
        }

        if ($target->id === $game->id) {
            return $target;
        }

        // Owned rows: repoint every platform row (V1 — many per game). V10's
        // unique key is (connection, platform_game_id), untouched by game_id.
        OwnedGame::where('user_id', $user->id)
            ->where('game_id', $game->id)
            ->update(['game_id' => $target->id]);

        // V21: wishlist ∩ owned = ∅. If the caller now owns the target, the
        // wishlist row is dropped (promote semantics) rather than repointed.
        $ownsTarget = OwnedGame::where('user_id', $user->id)
            ->where('game_id', $target->id)
            ->exists();

        $this->repointUnique(
            WishlistItem::where('user_id', $user->id),
            $game->id,
            $target->id,
            forceDrop: $ownsTarget,
        );

        // Meta follows the game so status/tags/notes/rating (V6 content) stay
        // attached to the corrected canonical game — never force-dropped.
        $this->repointUnique(
            UserGameMeta::where('user_id', $user->id),
            $game->id,
            $target->id,
            forceDrop: false,
        );

        // Mirrors GameMatcher::canonicalize's orphan cleanup, extended to the
        // union: a still-provisional row is removed only once nothing anywhere
        // references it (any user). An already-matched row (igdb_id set) is
        // real data, never deleted.
        if ($game->igdb_id === null && ! $this->referencedAnywhere($game)) {
            $game->delete();
        }

        return $target;
    }

    /**
     * Repoint the caller's unique-per-user row (wishlist / meta) from one game
     * to another. When a target-game row already exists — or forceDrop (V21:
     * caller owns the target) — the source row is dropped instead of colliding
     * with the unique(user_id, game_id) constraint.
     *
     * @param  Builder<WishlistItem|UserGameMeta>  $scoped
     */
    private function repointUnique(Builder $scoped, int $fromGameId, int $toGameId, bool $forceDrop): void
    {
        $source = (clone $scoped)->where('game_id', $fromGameId)->first();

        if ($source === null) {
            return;
        }

        $targetExists = (clone $scoped)->where('game_id', $toGameId)->exists();

        if ($forceDrop || $targetExists) {
            $source->delete();

            return;
        }

        $source->update(['game_id' => $toGameId]);
    }

    private function referencedAnywhere(Game $game): bool
    {
        return $game->ownedGames()->exists()
            || WishlistItem::where('game_id', $game->id)->exists()
            || UserGameMeta::where('game_id', $game->id)->exists();
    }
}
