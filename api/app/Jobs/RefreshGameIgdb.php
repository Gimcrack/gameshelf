<?php

namespace App\Jobs;

use App\Jobs\Concerns\RateLimitedIgdbSync;
use App\Models\Game;
use App\Services\Library\GameIgdbRefresh;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * T32/V39: one game's canonical-attr refresh, fanned out from
 * SyncLibraryIgdb. Bounded runtime (~2 IGDB calls); a failure here fails
 * this job alone — sibling games are separate jobs, so the V26/V38
 * "one game never aborts the batch" property holds structurally.
 */
class RefreshGameIgdb implements ShouldQueue
{
    use Queueable;
    use RateLimitedIgdbSync;

    public function __construct(public readonly int $gameId)
    {
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
