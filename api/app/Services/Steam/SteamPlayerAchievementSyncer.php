<?php

namespace App\Services\Steam;

use App\Models\GameAchievementDef;
use App\Models\OwnedGame;
use App\Models\OwnedGameAchievement;
use Illuminate\Support\Facades\Date;

/**
 * T68/V65: fetches unlock state using the CALLER's own steamid, never the
 * family member's - Steam attributes achievement progress to whichever
 * account actually plays the game, so this is correct unmodified for
 * `shared` rows (unlike playtime, V58, which has no such attribution).
 */
class SteamPlayerAchievementSyncer
{
    public function __construct(private readonly SteamClient $client)
    {
    }

    /**
     * V66: best-effort - a transient failure never fails the sync. A private
     * profile/no-stats response (V15-style null) is not an error, just skipped.
     */
    public function sync(OwnedGame $ownedGame, string $callerSteamId): void
    {
        try {
            $unlocks = $this->client->getPlayerAchievements($callerSteamId, (int) $ownedGame->platform_game_id);
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        if ($unlocks === null) {
            return;
        }

        $defIds = GameAchievementDef::query()
            ->where('platform', 'steam')
            ->where('platform_game_id', $ownedGame->platform_game_id)
            ->pluck('id', 'api_name');

        if ($defIds->isEmpty()) {
            // Defs not synced yet (T67 best-effort miss) - nothing to attach to.
            return;
        }

        $syncedAt = Date::now();

        foreach ($unlocks as $unlock) {
            $defId = $defIds->get($unlock['api_name']);
            if ($defId === null) {
                continue;
            }

            OwnedGameAchievement::updateOrCreate(
                [
                    'owned_game_id' => $ownedGame->id,
                    'game_achievement_def_id' => $defId,
                ],
                [
                    'unlocked' => $unlock['achieved'],
                    'unlocked_at' => $unlock['unlocked_at'] !== null
                        ? Date::createFromTimestamp($unlock['unlocked_at'])
                        : null,
                    'synced_at' => $syncedAt,
                ],
            );
        }
    }
}
