<?php

namespace App\Jobs;

use App\Models\Game;
use App\Models\User;
use App\Services\Igdb\GameMatcher;
use App\Services\Library\GameIgdbRefresh;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

class SyncLibraryIgdb implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $userId)
    {
    }

    /**
     * T31/V38: bulk "sync all IGDB" — matches provisional games across
     * every connection, then refreshes already-matched games' canonical
     * attrs. One game's failure never aborts the rest (mirrors V26).
     */
    public function handle(): void
    {
        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        // Snapshot before matching runs — a game matched during this same
        // pass already has full canonical data from that fetch (identical
        // to what refresh() would do), so re-fetching it immediately after
        // is pure waste (and needless extra IGDB call volume).
        $alreadyMatchedIds = $this->matchedGameIds($user);

        $this->matchProvisional($user);
        $this->refreshMatched($alreadyMatchedIds);
    }

    private function matchProvisional(User $user): void
    {
        foreach ($user->platformConnections as $connection) {
            try {
                app(GameMatcher::class)->matchConnection($connection);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * @return Collection<int, int>
     */
    private function matchedGameIds(User $user): Collection
    {
        return $user->ownedGames()
            ->join('games', 'games.id', '=', 'owned_games.game_id')
            ->whereNotNull('games.igdb_id')
            ->distinct()
            ->pluck('games.id');
    }

    /**
     * @param  Collection<int, int>  $gameIds
     */
    private function refreshMatched(Collection $gameIds): void
    {
        $refresh = app(GameIgdbRefresh::class);

        foreach ($gameIds as $gameId) {
            try {
                $game = Game::find($gameId);

                if ($game !== null) {
                    $refresh->refresh($game);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
