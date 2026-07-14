<?php

namespace App\Services\Gog;

use App\Enums\ConnectionStatus;
use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use Illuminate\Support\Facades\Date;
use RuntimeException;

class GogSyncer
{
    public function __construct(
        private readonly GogClient $client,
        private readonly GogTokenManager $tokens,
    ) {
    }

    /**
     * Ingest the connection's GOG library: refresh tokens when stale (V14),
     * upsert owned games (V10), create provisional game rows (V11).
     * GOG's owned-games API exposes no playtime, so playtime stays null —
     * unknown, not zero (§C, V12) — and no snapshots are appended (V16).
     */
    public function sync(PlatformConnection $connection): void
    {
        $accessToken = $this->tokens->freshAccessToken($connection);
        $products = $this->client->getOwnedGames($accessToken);
        $capturedAt = Date::now();

        foreach ($products as $product) {
            $this->ingestProduct($connection, $product, $capturedAt);
        }

        $connection->update([
            'status' => ConnectionStatus::Ok,
            'last_synced_at' => $capturedAt,
        ]);
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function ingestProduct(
        PlatformConnection $connection,
        array $product,
        \DateTimeInterface $capturedAt,
    ): void {
        $platformGameId = (string) $product['id'];

        $existing = OwnedGame::where('platform_connection_id', $connection->id)
            ->where('platform_game_id', $platformGameId)
            ->first();

        $gameId = $existing?->game_id ?? Game::create([
            'title' => $product['title'] ?? "GOG product {$platformGameId}",
        ])->id;

        // V10: keyed on (platform_connection_id, platform_game_id) — upsert only.
        OwnedGame::updateOrCreate(
            [
                'platform_connection_id' => $connection->id,
                'platform_game_id' => $platformGameId,
            ],
            [
                'user_id' => $connection->user_id,
                'game_id' => $gameId,
                'playtime_minutes' => null,
                'added_at' => $existing?->added_at ?? $capturedAt,
            ],
        );
    }
}
