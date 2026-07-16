<?php

namespace App\Jobs;

use App\Enums\ConnectionStatus;
use App\Models\PlatformConnection;
use App\Services\Gog\GogSyncer;
use App\Services\Steam\SteamSyncer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

class SyncConnection implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $connectionId)
    {
    }

    /**
     * V8: all sync work happens here, off the request cycle.
     */
    public function handle(): void
    {
        $connection = PlatformConnection::find($this->connectionId);

        if ($connection === null || $connection->status === ConnectionStatus::Disconnected) {
            return;
        }

        // V19: the synthetic manual connection has nothing to sync.
        if ($connection->platform === 'manual') {
            return;
        }

        $connection->update(['status' => ConnectionStatus::Syncing]);

        try {
            match ($connection->platform) {
                'steam' => app(SteamSyncer::class)->sync($connection),
                'gog' => app(GogSyncer::class)->sync($connection),
                default => throw new \RuntimeException("Unsupported platform: {$connection->platform}"),
            };
        } catch (\Throwable $e) {
            // V9: surface failure on the connection, then rethrow for queue retry.
            $connection->update(['status' => ConnectionStatus::Error]);

            throw $e;
        }

        if ($connection->refresh()->status === ConnectionStatus::Ok) {
            $this->matchGames($connection);
        }
    }

    /**
     * V50: platform sync auto-queues IGDB work, fanned out per V39 (refreshing
     * every matched game inline would re-blow the queue timeout, B8). Provisional
     * games match via their connection (V4 platform_game_id cache path); matched
     * games gone IGDB-stale (>24h) get a per-game refresh — fresh (<24h) games
     * are skipped so daily (V5) and "sync now" re-syncs stay cheap. IGDB
     * enrichment failure never fails the sync (V11); child jobs isolate per V26.
     */
    private function matchGames(PlatformConnection $connection): void
    {
        try {
            // Snapshot before the match job runs — a game matched this pass is
            // stamped fresh and so is (correctly) excluded from the refresh half.
            $staleGameIds = $this->staleMatchedGameIds($connection);

            MatchConnectionIgdb::dispatch($connection->id);

            foreach ($staleGameIds as $gameId) {
                RefreshGameIgdb::dispatch($gameId);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * V50: this connection's owned matched games whose canonical row is
     * IGDB-stale — igdb_synced_at null (never) or older than 24h.
     *
     * @return Collection<int, int>
     */
    private function staleMatchedGameIds(PlatformConnection $connection): Collection
    {
        return $connection->ownedGames()
            ->join('games', 'games.id', '=', 'owned_games.game_id')
            ->whereNotNull('games.igdb_id')
            ->where(function ($query) {
                $query->whereNull('games.igdb_synced_at')
                    ->orWhere('games.igdb_synced_at', '<', now()->subDay());
            })
            ->pluck('games.id')
            ->unique()
            ->values();
    }
}
