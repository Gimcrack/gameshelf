<?php

namespace App\Services\Xbox;

use App\Enums\ConnectionStatus;
use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use Illuminate\Support\Facades\Date;

class XboxSyncer
{
    public function __construct(
        private readonly XboxClient $client,
        private readonly XboxTokenManager $tokens,
        private readonly XboxAchievementSyncer $achievementSyncer,
    ) {
    }

    /**
     * Ingest the connection's Xbox Live title history: refresh tokens when
     * stale (V14), upsert owned games (V10), prune rows for titles absent
     * from the fresh response (V24-style, mirrors SteamSyncer — GOG doesn't
     * reconcile, but Xbox's titlehub response is a full current snapshot
     * same as Steam's GetOwnedGames). Titlehub exposes no purchase-price
     * playtime figure, so playtime stays null — unknown, not zero (§C, V12).
     */
    public function sync(PlatformConnection $connection): void
    {
        $credentials = $this->tokens->freshXstsCredentials($connection);
        $titles = $this->client->getTitleHistory(
            $connection->external_account_id,
            $credentials['xsts_token'],
            $credentials['user_hash'],
        );

        // §C caveat: titlehub also returns non-game entries (apps); only
        // "Game" type titles belong in the library.
        $games = array_values(array_filter(
            $titles,
            fn (array $title) => ($title['type'] ?? null) === 'Game',
        ));

        $capturedAt = Date::now();

        foreach ($games as $title) {
            $this->ingestTitle($connection, $title, $capturedAt, $credentials);
        }

        $currentTitleIds = array_map(fn (array $t) => (string) $t['titleId'], $games);

        $connection->ownedGames()
            ->whereNotIn('platform_game_id', $currentTitleIds)
            ->delete();

        $connection->update([
            'status' => ConnectionStatus::Ok,
            'last_synced_at' => $capturedAt,
        ]);
    }

    /**
     * @param  array<string, mixed>  $title
     * @param  array{xsts_token: string, user_hash: string, xuid: string}  $credentials
     */
    private function ingestTitle(
        PlatformConnection $connection,
        array $title,
        \DateTimeInterface $capturedAt,
        array $credentials,
    ): void {
        $platformGameId = (string) $title['titleId'];

        $existing = OwnedGame::where('platform_connection_id', $connection->id)
            ->where('platform_game_id', $platformGameId)
            ->first();

        $gameId = $existing?->game_id ?? Game::create([
            'title' => $title['name'] ?? "Xbox title {$platformGameId}",
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
                'playtime_minutes' => null,
                'added_at' => $existing?->added_at ?? $capturedAt,
            ],
        );

        // T69/V63: 1 call yields both defs + unlock state, best-effort (V66).
        $this->achievementSyncer->sync(
            $ownedGame,
            $credentials['xuid'],
            $credentials['xsts_token'],
            $credentials['user_hash'],
        );
    }
}
