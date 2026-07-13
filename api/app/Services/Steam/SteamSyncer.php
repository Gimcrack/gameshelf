<?php

namespace App\Services\Steam;

use App\Enums\ConnectionStatus;
use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use App\Models\PlaytimeSnapshot;
use Illuminate\Support\Facades\Date;

class SteamSyncer
{
    public function __construct(private readonly SteamClient $client)
    {
    }

    /**
     * Ingest the connection's Steam library: upsert owned games (V10),
     * create provisional game rows (V11 pre-wiring, IGDB match is T4),
     * and append playtime snapshots (V16).
     */
    public function sync(PlatformConnection $connection): void
    {
        $games = $this->client->getOwnedGames($connection->external_account_id);

        if ($games === null) {
            // V15: private profile is a distinct error state, not an empty library.
            $connection->update(['status' => ConnectionStatus::ErrorPrivate]);

            return;
        }

        $capturedAt = Date::now();

        foreach ($games as $steamGame) {
            $this->ingestGame($connection, $steamGame, $capturedAt);
        }

        $connection->update([
            'status' => ConnectionStatus::Ok,
            'last_synced_at' => $capturedAt,
        ]);
    }

    /**
     * @param  array<string, mixed>  $steamGame
     */
    private function ingestGame(
        PlatformConnection $connection,
        array $steamGame,
        \DateTimeInterface $capturedAt,
    ): void {
        $platformGameId = (string) $steamGame['appid'];
        $playtime = $steamGame['playtime_forever'] ?? null;
        $lastPlayed = isset($steamGame['rtime_last_played']) && $steamGame['rtime_last_played'] > 0
            ? Date::createFromTimestamp($steamGame['rtime_last_played'])
            : null;

        $existing = OwnedGame::where('platform_connection_id', $connection->id)
            ->where('platform_game_id', $platformGameId)
            ->first();

        $gameId = $existing?->game_id ?? Game::create([
            'title' => $steamGame['name'] ?? "Steam app {$platformGameId}",
        ])->id;

        // V10: keyed on (platform_connection_id, platform_game_id) — upsert only.
        $ownedGame = OwnedGame::updateOrCreate(
            [
                'platform_connection_id' => $connection->id,
                'platform_game_id' => $platformGameId,
            ],
            [
                'user_id' => $connection->user_id,
                'game_id' => $gameId,
                'playtime_minutes' => $playtime,
                'last_played_at' => $lastPlayed,
                'added_at' => $existing?->added_at ?? $capturedAt,
            ],
        );

        // V16: snapshot every sync — only for games with actual playtime data.
        if ($playtime !== null) {
            PlaytimeSnapshot::create([
                'owned_game_id' => $ownedGame->id,
                'playtime_minutes' => $playtime,
                'captured_at' => $capturedAt,
            ]);
        }
    }
}
