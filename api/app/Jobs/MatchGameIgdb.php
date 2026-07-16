<?php

namespace App\Jobs;

use App\Jobs\Concerns\RateLimitedIgdbSync;
use App\Models\Game;
use App\Models\User;
use App\Services\Igdb\GameMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * T50/V48: one union game's provisional-match, fanned out from SyncLibraryIgdb
 * for wishlist-only + meta-orphan games (B15: those never rode a connection
 * match job, so bulk "sync all IGDB" silently ignored them). Bounded runtime;
 * a failure here fails this job alone (V26/V38 per-game isolation holds
 * structurally, mirrors RefreshGameIgdb).
 */
class MatchGameIgdb implements ShouldQueue
{
    use Queueable;
    use RateLimitedIgdbSync;

    public function __construct(
        public readonly int $userId,
        public readonly int $gameId,
    ) {
    }

    public function handle(GameMatcher $matcher): void
    {
        $user = User::find($this->userId);
        $game = Game::find($this->gameId);

        if ($user === null || $game === null || $game->igdb_id !== null) {
            return;
        }

        $matcher->matchGame($user, $game);
    }
}
