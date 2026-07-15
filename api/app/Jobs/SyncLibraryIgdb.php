<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/**
 * T31/V38 + T32/V39: bulk "sync all IGDB" orchestrator. Makes no IGDB
 * calls itself (B8: the monolithic version serial-looped the whole
 * library and blew the queue timeout) — it only fans out bounded child
 * jobs: one MatchConnectionIgdb per connection, one RefreshGameIgdb per
 * already-matched game.
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

        foreach ($user->platformConnections as $connection) {
            MatchConnectionIgdb::dispatch($connection->id);
        }

        foreach ($alreadyMatchedIds as $gameId) {
            RefreshGameIgdb::dispatch($gameId);
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
}
