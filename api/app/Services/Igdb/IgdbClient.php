<?php

namespace App\Services\Igdb;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

class IgdbClient
{
    private const GAMES_URL = 'https://api.igdb.com/v4/games';

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
