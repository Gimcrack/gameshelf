<?php

namespace App\Services\Steam;

use App\Enums\ConnectionStatus;
use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use Illuminate\Support\Facades\Date;

/**
 * V58: ingests a manually-added family member's shared library — category-62
 * (public appdetails, best-effort) games the caller doesn't already own
 * directly. Mirrors SteamSyncer's V15/V24 shapes, scoped to one synthetic
 * steam_family connection.
 */
class SteamFamilySyncer
{
    public function __construct(
        private readonly SteamClient $client,
        private readonly SteamAchievementDefSyncer $achievementDefSyncer,
        private readonly SteamPlayerAchievementSyncer $playerAchievementSyncer,
    ) {
    }

    public function sync(PlatformConnection $connection): void
    {
        $games = $this->client->getOwnedGames($connection->external_account_id);

        if ($games === null) {
            // V15-style: member's profile went private — short-circuit before
            // reconciliation so it never wrongly wipes shared rows.
            $connection->update(['status' => ConnectionStatus::ErrorPrivate]);

            return;
        }

        // Caller's own real ownership always wins (V21/V42-class rule) —
        // exclude appids the caller already owns via their own steam connection.
        $ownAppIds = OwnedGame::query()
            ->where('user_id', $connection->user_id)
            ->whereHas('connection', fn ($q) => $q->where('platform', 'steam'))
            ->pluck('platform_game_id')
            ->all();

        $shared = array_values(array_filter(
            $games,
            fn (array $g) => ! in_array((string) $g['appid'], $ownAppIds, true)
                && $this->isFamilyShared((int) $g['appid']),
        ));

        $capturedAt = Date::now();

        // T68/V65: achievement unlocks use the CALLER's own steamid, never
        // the family member's - look it up once, not per game. A caller
        // without their own direct Steam connection just gets no achievement
        // data for shared rows (nothing to query against).
        $callerSteamId = PlatformConnection::where('user_id', $connection->user_id)
            ->where('platform', 'steam')
            ->value('external_account_id');

        foreach ($shared as $steamGame) {
            $this->ingestGame($connection, $steamGame, $capturedAt, $callerSteamId);
        }

        // V24-style prune, scoped to this connection only.
        $currentAppIds = array_map(fn (array $g) => (string) $g['appid'], $shared);

        $connection->ownedGames()
            ->whereNotIn('platform_game_id', $currentAppIds)
            ->delete();

        $connection->update([
            'status' => ConnectionStatus::Ok,
            'last_synced_at' => $capturedAt,
        ]);
    }

    /**
     * Best-effort corroboration signal (I.ext) — a transient failure never
     * fails sync, and never wrongly includes a game (mirrors V31 tolerance,
     * but "no answer" means excluded here, not "unknown but shown").
     */
    private function isFamilyShared(int $appId): bool
    {
        try {
            return in_array(
                SteamClient::FAMILY_SHARING_CATEGORY_ID,
                $this->client->appCategoryIds($appId),
                true,
            );
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $steamGame
     */
    private function ingestGame(
        PlatformConnection $connection,
        array $steamGame,
        \DateTimeInterface $capturedAt,
        ?string $callerSteamId,
    ): void {
        $platformGameId = (string) $steamGame['appid'];

        // T67/V65: defs are appid-level, needed here too since T68's unlock
        // sync covers shared rows via the caller's own steamid.
        $this->achievementDefSyncer->sync($platformGameId);

        $existing = OwnedGame::where('platform_connection_id', $connection->id)
            ->where('platform_game_id', $platformGameId)
            ->first();

        $gameId = $existing?->game_id ?? Game::create([
            'title' => $steamGame['name'] ?? "Steam app {$platformGameId}",
        ])->id;

        $ownedGame = OwnedGame::updateOrCreate(
            [
                'platform_connection_id' => $connection->id,
                'platform_game_id' => $platformGameId,
            ],
            [
                'user_id' => $connection->user_id,
                'game_id' => $gameId,
                // V58: shared playtime belongs to the family member's
                // account, not the caller's — never surfaced as caller data.
                'playtime_minutes' => null,
                'added_at' => $existing?->added_at ?? $capturedAt,
                'shared' => true,
            ],
        );

        // T68/V65: caller's own steamid, not the family member's.
        if ($callerSteamId !== null) {
            $this->playerAchievementSyncer->sync($ownedGame, $callerSteamId);
        }
    }
}
