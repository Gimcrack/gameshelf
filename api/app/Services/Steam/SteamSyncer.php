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
    public function __construct(
        private readonly SteamClient $client,
        private readonly SteamAchievementDefSyncer $achievementDefSyncer,
        private readonly SteamPlayerAchievementSyncer $playerAchievementSyncer,
    ) {
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

        // V41: F2P ingested iff playtime > 0 — zero-playtime F2P is B3
        // noise, never ingested. The filtered list also defines the V24
        // fresh-set below, so legacy zero-playtime F2P rows get pruned.
        $games = array_values(array_filter(
            $games,
            fn (array $g) => ! ($g['free_to_play'] ?? false) || ($g['playtime_forever'] ?? 0) > 0,
        ));

        $capturedAt = Date::now();

        foreach ($games as $steamGame) {
            $this->ingestGame($connection, $steamGame, $capturedAt);
        }

        // V24: sync reflects Steam's current state, not just accretes it —
        // rows for appids absent from this response (removed from the
        // account, or legacy noise from the pre-V23 free-games bug) are
        // pruned. Snapshot history cascades with the row (V16 data is only
        // meaningful for games still in the library).
        $currentAppIds = array_map(fn (array $g) => (string) $g['appid'], $games);

        $connection->ownedGames()
            ->whereNotIn('platform_game_id', $currentAppIds)
            ->delete();

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

        $attributes = [
            'user_id' => $connection->user_id,
            'game_id' => $gameId,
            'playtime_minutes' => $playtime,
            'last_played_at' => $lastPlayed,
            'added_at' => $existing?->added_at ?? $capturedAt,
            // V41: true = appid appeared only in the extended fetch.
            'free_to_play' => $steamGame['free_to_play'] ?? false,
        ];

        // V31: best-effort, refetched every sync. A transient failure omits
        // the key entirely rather than overwriting an already-known status
        // with null — only a row that's never once succeeded stays null.
        $deckStatus = $this->deckStatus($platformGameId);
        if ($deckStatus !== null) {
            $attributes['deck_status'] = $deckStatus;
        }

        // T67: achievement definitions, keyed per appid (V63) - best-effort,
        // never fails the sync.
        $this->achievementDefSyncer->sync($platformGameId);

        // V10: keyed on (platform_connection_id, platform_game_id) — upsert only.
        $ownedGame = OwnedGame::updateOrCreate(
            [
                'platform_connection_id' => $connection->id,
                'platform_game_id' => $platformGameId,
            ],
            $attributes,
        );

        // T68/V65: caller's own steamid throughout, not any family member's.
        $this->playerAchievementSyncer->sync($ownedGame, $connection->external_account_id);

        // V16: snapshot every sync — only for games with actual playtime data.
        if ($playtime !== null) {
            PlaytimeSnapshot::create([
                'owned_game_id' => $ownedGame->id,
                'playtime_minutes' => $playtime,
                'captured_at' => $capturedAt,
            ]);
        }
    }

    /**
     * V31: mirrors V11/timeToBeat tolerance — failure never fails sync.
     */
    private function deckStatus(string $appId): ?string
    {
        try {
            return $this->client->deckCompatibility((int) $appId);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
