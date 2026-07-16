<?php

namespace App\Services\Xbox;

use App\Models\GameAchievementDef;
use App\Models\OwnedGame;
use App\Models\OwnedGameAchievement;
use Illuminate\Support\Facades\Date;

/**
 * T69/V63: unlike Steam's 2-call split (defs + per-user unlock), Xbox's
 * achievements endpoint returns both in 1 call per title - this upserts
 * game_achievement_defs and owned_game_achievements together.
 */
class XboxAchievementSyncer
{
    public function __construct(private readonly XboxClient $client)
    {
    }

    /**
     * V66: best-effort - a transient failure never fails the sync.
     */
    public function sync(
        OwnedGame $ownedGame,
        string $xuid,
        string $xstsToken,
        string $userHash,
    ): void {
        try {
            $achievements = $this->client->getAchievements(
                $xuid,
                $xstsToken,
                $userHash,
                $ownedGame->platform_game_id,
            );
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        $now = Date::now();

        foreach ($achievements as $achievement) {
            $def = GameAchievementDef::updateOrCreate(
                [
                    'platform' => 'xbox',
                    'platform_game_id' => $ownedGame->platform_game_id,
                    'api_name' => $achievement['api_name'],
                ],
                [
                    'name' => $achievement['name'],
                    'description' => $achievement['description'],
                    'icon_url' => $achievement['icon_url'],
                    'points' => $achievement['points'],
                    'fetched_at' => $now,
                ],
            );

            OwnedGameAchievement::updateOrCreate(
                [
                    'owned_game_id' => $ownedGame->id,
                    'game_achievement_def_id' => $def->id,
                ],
                [
                    'unlocked' => $achievement['achieved'],
                    'unlocked_at' => $achievement['unlocked_at'] !== null
                        ? Date::parse($achievement['unlocked_at'])
                        : null,
                    'synced_at' => $now,
                ],
            );
        }
    }
}
