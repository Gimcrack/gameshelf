<?php

namespace App\Services\Library;

use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\User;

/**
 * T29/V34: manual "fix match" — reuses V7's canonical-row dedupe exactly
 * (mirrors GameMatcher::canonicalize), but only ever repoints the calling
 * user's own owned_games rows. Available regardless of current match state:
 * a provisional miss or an already-matched-but-wrong game, both correctable.
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

        OwnedGame::where('user_id', $user->id)
            ->where('game_id', $game->id)
            ->update(['game_id' => $target->id]);

        // Mirrors GameMatcher::canonicalize's orphan cleanup — only a still-
        // provisional row with no remaining owners (any user) is removed.
        // An already-matched row (igdb_id set) is real data, never deleted.
        if ($game->igdb_id === null && ! $game->ownedGames()->exists()) {
            $game->delete();
        }

        return $target;
    }
}
