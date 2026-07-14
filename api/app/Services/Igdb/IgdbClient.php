<?php

namespace App\Services\Igdb;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

class IgdbClient
{
    private const GAMES_URL = 'https://api.igdb.com/v4/games';

    private const TIME_TO_BEAT_URL = 'https://api.igdb.com/v4/game_time_to_beats';

    private const EXTERNAL_GAMES_URL = 'https://api.igdb.com/v4/external_games';

    /** external_games.category values (I.igdb external mapping). */
    public const EXTERNAL_STEAM = 1;

    public const EXTERNAL_GOG = 5;

    // I.igdb: at most 4 requests per second.
    private const MAX_REQUESTS_PER_SECOND = 4;

    public function __construct(
        private readonly string $clientId,
        private readonly TwitchAuth $auth,
    ) {
    }

    /**
     * Search IGDB by title; returns the best candidate record or null.
     *
     * @return array<string, mixed>|null
     */
    public function searchGame(string $title): ?array
    {
        $this->throttle();

        $query = sprintf(
            'search "%s"; fields name,cover.url,genres.name,first_release_date; limit 5;',
            str_replace('"', '\"', $title),
        );

        $response = Http::withHeaders([
            'Client-ID' => $this->clientId,
            'Authorization' => 'Bearer '.$this->auth->token(),
        ])->withBody($query, 'text/plain')->post(self::GAMES_URL);

        if ($response->failed()) {
            throw new RuntimeException('IGDB games request failed: '.$response->status());
        }

        $results = $response->json();

        if (! is_array($results) || $results === []) {
            return null;
        }

        return $this->bestCandidate($title, $results);
    }

    /**
     * Fetch one game by IGDB id, or null when the id is unknown.
     *
     * @return array<string, mixed>|null
     */
    public function getGame(int $igdbId): ?array
    {
        $this->throttle();

        $query = sprintf(
            'fields name,cover.url,genres.name,first_release_date; where id = %d; limit 1;',
            $igdbId,
        );

        $response = Http::withHeaders([
            'Client-ID' => $this->clientId,
            'Authorization' => 'Bearer '.$this->auth->token(),
        ])->withBody($query, 'text/plain')->post(self::GAMES_URL);

        if ($response->failed()) {
            throw new RuntimeException('IGDB games request failed: '.$response->status());
        }

        $results = $response->json();

        return is_array($results) && $results !== [] ? $results[0] : null;
    }

    /**
     * IGDB game id for a platform store id (steam appid / gog product id),
     * or null when IGDB has no mapping.
     */
    public function gameIdFromExternal(int $category, string $uid): ?int
    {
        $this->throttle();

        $query = sprintf(
            'fields game; where uid = "%s" & category = %d; limit 1;',
            str_replace('"', '\"', $uid),
            $category,
        );

        $response = Http::withHeaders([
            'Client-ID' => $this->clientId,
            'Authorization' => 'Bearer '.$this->auth->token(),
        ])->withBody($query, 'text/plain')->post(self::EXTERNAL_GAMES_URL);

        if ($response->failed()) {
            throw new RuntimeException('IGDB external_games request failed: '.$response->status());
        }

        $game = $response->json('0.game');

        return $game === null ? null : (int) $game;
    }

    /**
     * Platform store id for an IGDB game (reverse of gameIdFromExternal),
     * or null when IGDB has no mapping.
     */
    public function externalUid(int $igdbId, int $category): ?string
    {
        $this->throttle();

        $query = sprintf(
            'fields uid; where game = %d & category = %d; limit 1;',
            $igdbId,
            $category,
        );

        $response = Http::withHeaders([
            'Client-ID' => $this->clientId,
            'Authorization' => 'Bearer '.$this->auth->token(),
        ])->withBody($query, 'text/plain')->post(self::EXTERNAL_GAMES_URL);

        if ($response->failed()) {
            throw new RuntimeException('IGDB external_games request failed: '.$response->status());
        }

        $uid = $response->json('0.uid');

        return $uid === null ? null : (string) $uid;
    }

    /**
     * §C: time-to-beat backs the quick-wins collection — minutes for the
     * "normally" pace, or null when IGDB has no data for the game.
     */
    public function timeToBeat(int $igdbId): ?int
    {
        $this->throttle();

        $query = sprintf('fields normally; where game_id = %d; limit 1;', $igdbId);

        $response = Http::withHeaders([
            'Client-ID' => $this->clientId,
            'Authorization' => 'Bearer '.$this->auth->token(),
        ])->withBody($query, 'text/plain')->post(self::TIME_TO_BEAT_URL);

        if ($response->failed()) {
            throw new RuntimeException('IGDB game_time_to_beats request failed: '.$response->status());
        }

        $seconds = $response->json('0.normally');

        return $seconds === null ? null : intdiv((int) $seconds, 60);
    }

    /**
     * Prefer an exact (case-insensitive) title match, else the first result.
     *
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function bestCandidate(string $title, array $results): array
    {
        foreach ($results as $result) {
            if (strcasecmp($result['name'] ?? '', $title) === 0) {
                return $result;
            }
        }

        return $results[0];
    }

    private function throttle(): void
    {
        while (! RateLimiter::attempt('igdb-requests', self::MAX_REQUESTS_PER_SECOND, fn () => null, 1)) {
            usleep(250_000);
        }
    }
}
