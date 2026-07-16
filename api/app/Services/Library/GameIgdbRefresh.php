<?php

namespace App\Services\Library;

use App\Models\Game;
use App\Services\Igdb\IgdbClient;
use App\Services\Igdb\IgdbGameAttributes;

/**
 * T30/V35: on-demand re-fetch of one already-matched game's IGDB data —
 * mirrors the attrs GameMatcher::canonicalize and GameFromIgdb apply,
 * just triggered by the user instead of a sync/add.
 */
class GameIgdbRefresh
{
    public function __construct(private readonly IgdbClient $client)
    {
    }

    /**
     * Null return means IGDB no longer knows this igdb_id.
     */
    public function refresh(Game $game): ?Game
    {
        $record = $this->client->getGame($game->igdb_id);

        if ($record === null) {
            return null;
        }

        $game->update([
            ...IgdbGameAttributes::fromRecord($record),
            'time_to_beat_minutes' => $this->timeToBeat($game->igdb_id),
            // V50: refresh resets the freshness clock (24h gate, T51).
            'igdb_synced_at' => now(),
        ]);

        return $game->fresh();
    }

    /**
     * §C: best-effort, mirrors the matcher — missing ttb never blocks refresh.
     */
    private function timeToBeat(int $igdbId): ?int
    {
        try {
            return $this->client->timeToBeat($igdbId);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
