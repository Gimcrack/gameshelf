<?php

namespace App\Jobs;

use App\Models\Game;
use App\Models\User;
use App\Services\Igdb\GameMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

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

    public function __construct(
        public readonly int $userId,
        public readonly int $gameId,
    ) {
    }

    /**
     * V39: rate limit enforced at the queue layer — an over-budget job is
     * released back with a delay instead of sleep-blocking a worker.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RateLimited('igdb-sync')];
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
