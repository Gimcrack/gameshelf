<?php

namespace App\Services\Steam;

use App\Models\GameAchievementDef;
use Illuminate\Support\Facades\Date;

/**
 * T67/V63: achievement definitions are keyed per (platform, platform_game_id),
 * not per canonical `games` row - shared by SteamSyncer (owned) and
 * SteamFamilySyncer (shared, V65 needs defs there too for T68's unlock sync).
 */
class SteamAchievementDefSyncer
{
    public function __construct(private readonly SteamClient $client)
    {
    }

    /**
     * Best-effort, mirrors deckStatus/isFamilyShared tolerance - a transient
     * failure never fails the sync. getSchemaForGame is cached forever
     * client-side, so repeat syncs for an already-fetched appid are cheap.
     */
    public function sync(string $appId): void
    {
        try {
            $defs = $this->client->getSchemaForGame((int) $appId);
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        $fetchedAt = Date::now();

        foreach ($defs as $def) {
            GameAchievementDef::updateOrCreate(
                [
                    'platform' => 'steam',
                    'platform_game_id' => $appId,
                    'api_name' => $def['api_name'],
                ],
                [
                    'name' => $def['name'],
                    'description' => $def['description'],
                    'icon_url' => $def['icon_url'],
                    // Steam has no points concept (T67/§C).
                    'points' => null,
                    'fetched_at' => $fetchedAt,
                ],
            );
        }
    }
}
