<?php

namespace App\Services\Library;

use App\Models\Game;
use App\Services\Igdb\IgdbClient;
use App\Services\Igdb\IgdbGameAttributes;

class GameFromIgdb
{
    public function __construct(private readonly IgdbClient $client)
    {
    }

    /**
     * V7: one canonical row per igdb_id — reuse before create. Returns null
     * when IGDB doesn't know the id.
     */
    public function findOrCreate(int $igdbId): ?Game
    {
        $existing = Game::where('igdb_id', $igdbId)->first();

        if ($existing !== null) {
            return $existing;
        }

        $record = $this->client->getGame($igdbId);

        if ($record === null) {
            return null;
        }

        return Game::create([
            ...IgdbGameAttributes::fromRecord($record),
            'time_to_beat_minutes' => $this->timeToBeat($igdbId),
        ]);
    }

    /**
     * Best-effort, mirroring the matcher: missing ttb never blocks the add.
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
