<?php

namespace App\Jobs;

use App\Enums\ConnectionStatus;
use App\Models\PlatformConnection;
use App\Services\Gog\GogSyncer;
use App\Services\Igdb\GameMatcher;
use App\Services\Steam\SteamSyncer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
     * IGDB matching is enrichment — its failure never fails the sync (V11).
     */
    private function matchGames(PlatformConnection $connection): void
    {
        try {
            app(GameMatcher::class)->matchConnection($connection);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
