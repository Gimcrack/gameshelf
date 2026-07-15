<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\Library\GameIgdbRefresh;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * T32/V39: one game's canonical-attr refresh, fanned out from
 * SyncLibraryIgdb. Bounded runtime (~2 IGDB calls); a failure here fails
 * this job alone — sibling games are separate jobs, so the V26/V38
 * "one game never aborts the batch" property holds structurally.
 */
class RefreshGameIgdb implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $gameId)
    {
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

    public function handle(GameIgdbRefresh $refresh): void
    {
        $game = Game::find($this->gameId);

        if ($game === null || $game->igdb_id === null) {
            return;
        }

        $refresh->refresh($game);
    }
}
