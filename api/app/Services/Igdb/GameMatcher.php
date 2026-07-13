<?php

namespace App\Services\Igdb;

use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

class GameMatcher
{
    private const MISS = '__igdb_miss__';

    private const MISS_TTL_HOURS = 24;

    public function __construct(private readonly IgdbClient $client)
    {
    }

    /**
     * Match every provisional (igdb_id null) game owned via this connection.
     */
    public function matchConnection(PlatformConnection $connection): void
    {
        $unmatched = $connection->ownedGames()
            ->with('game')
            ->whereHas('game', fn ($query) => $query->whereNull('igdb_id'))
            ->get();

        foreach ($unmatched as $ownedGame) {
            $this->match($connection->platform, $ownedGame);
        }
    }

    private function match(string $platform, OwnedGame $ownedGame): void
    {
        // V4: known platform_game_id resolves from cache, never re-queries IGDB.
        $cacheKey = "igdb-match:{$platform}:{$ownedGame->platform_game_id}";
        $cached = Cache::get($cacheKey);

        if ($cached === self::MISS) {
            return;
        }

        $igdb = $cached ?? $this->client->searchGame($ownedGame->game->title);

        if ($igdb === null) {
            // V11: no match — provisional row stays; short-lived miss marker
            // so retries happen eventually without hammering IGDB.
            Cache::put($cacheKey, self::MISS, now()->addHours(self::MISS_TTL_HOURS));

            return;
        }

        Cache::forever($cacheKey, $igdb);
        $this->canonicalize($ownedGame, $igdb);
    }

    /**
     * V7: one games row per real-world game, keyed by unique igdb_id.
     *
     * @param  array<string, mixed>  $igdb
     */
    private function canonicalize(OwnedGame $ownedGame, array $igdb): void
    {
        $provisional = $ownedGame->game;
        $canonical = Game::where('igdb_id', $igdb['id'])->first();

        if ($canonical !== null && $canonical->id !== $provisional->id) {
            $ownedGame->update(['game_id' => $canonical->id]);

            if ($provisional->igdb_id === null && ! $provisional->ownedGames()->exists()) {
                $provisional->delete();
            }

            return;
        }

        $provisional->update([
            'igdb_id' => $igdb['id'],
            'title' => $igdb['name'],
            'cover_url' => $igdb['cover']['url'] ?? null,
            'genres' => array_map(
                fn (array $genre) => $genre['name'],
                $igdb['genres'] ?? [],
            ),
            'release_date' => isset($igdb['first_release_date'])
                ? Date::createFromTimestamp($igdb['first_release_date'])->toDateString()
                : null,
        ]);
    }
}
