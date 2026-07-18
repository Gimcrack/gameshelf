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

    private const GENRES_URL = 'https://api.igdb.com/v4/genres';

    private const DISCOVER_FIELDS = 'name,cover.url,genres.name,first_release_date,total_rating';

    // T16: expand similar_games sub-object in one call — no second round
    // trip per hit, single throttled request per seed (V4 caches the rest).
    private const SIMILAR_FIELDS = 'similar_games.name,similar_games.cover.url,'
        .'similar_games.genres.name,similar_games.first_release_date,similar_games.total_rating';

    // T18: 2-level nested expansion (franchises → games) — one call per
    // owned game returns every franchise it belongs to plus that
    // franchise's full game list.
    private const FRANCHISE_FIELDS = 'franchises.name,franchises.games.name,franchises.games.cover.url,'
        .'franchises.games.genres.name,franchises.games.first_release_date,franchises.games.total_rating';

    private const UPCOMING_FIELDS = 'name,cover.url,genres.name,first_release_date,total_rating';

    // V30: themes/keywords/game_modes ride along on the same request that
    // already fetches genres — no extra IGDB call, no throttle/cache change.
    // T27/V32,V33: age_ratings (ESRB) + multiplayer_modes ride the same
    // request for the same reason.
    // B7/V37: `organization`+nested `rating_category.rating` — IGDB's real
    // shape (verified live). The old flat `category`/`rating` field names
    // don't exist; IGDB drops unknown fields silently (no error), which is
    // exactly how this went unnoticed.
    // T80/V76: player_perspectives rides the same request too — VR is one of
    // its values ("Virtual Reality"), no extra IGDB call.
    private const CANONICAL_FIELDS = 'name,cover.url,genres.name,themes.name,keywords.name,game_modes.name,'
        .'first_release_date,age_ratings.organization,age_ratings.rating_category.rating,'
        .'multiplayer_modes.campaigncoop,multiplayer_modes.dropin,multiplayer_modes.lancoop,'
        .'multiplayer_modes.offlinecoop,multiplayer_modes.offlinemax,multiplayer_modes.onlinecoop,'
        .'multiplayer_modes.onlinemax,multiplayer_modes.splitscreen,multiplayer_modes.splitscreenonline,'
        .'player_perspectives.name';

    /** §I discover browse sort vocabulary → IGDB order clauses. */
    private const BROWSE_SORTS = [
        'rating' => 'total_rating desc',
        'release' => 'first_release_date desc',
        'popularity' => 'total_rating_count desc',
    ];

    /**
     * external_games.external_game_source ids (I.igdb external mapping).
     * IGDB overhauled external_games — the old `category` field was renamed
     * to `external_game_source`; the numeric ids are unchanged (B11/V45,
     * live-verified). Querying the dropped `category` field returns [] with
     * no error, so every mapping silently missed.
     */
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
            'search "%s"; fields %s; limit 5;',
            str_replace('"', '\"', $title),
            self::CANONICAL_FIELDS,
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
     * Multi-hit title search for the discover proxy (§C.discovery).
     *
     * @return list<array<string, mixed>>
     */
    public function searchGames(string $q, int $limit = 20): array
    {
        $this->throttle();

        $query = sprintf(
            'search "%s"; fields %s; limit %d;',
            str_replace('"', '\"', $q),
            self::DISCOVER_FIELDS,
            $limit,
        );

        $response = Http::withHeaders([
            'Client-ID' => $this->clientId,
            'Authorization' => 'Bearer '.$this->auth->token(),
        ])->withBody($query, 'text/plain')->post(self::GAMES_URL);

        if ($response->failed()) {
            throw new RuntimeException('IGDB games request failed: '.$response->status());
        }

        $results = $response->json();

        return is_array($results) ? $results : [];
    }

    /**
     * Catalogue page for the discover proxy. Sort field is guarded non-null
     * so IGDB doesn't float unrated/undated records to the top.
     *
     * @return list<array<string, mixed>>
     */
    public function browseGames(?int $genreId, string $sort, int $page, int $perPage = 20): array
    {
        $this->throttle();

        $order = self::BROWSE_SORTS[$sort]
            ?? throw new RuntimeException("Unknown browse sort: {$sort}");
        $sortField = explode(' ', $order)[0];

        $where = ["{$sortField} != null"];

        if ($genreId !== null) {
            $where[] = "genres = {$genreId}";
        }

        $query = sprintf(
            'fields %s; where %s; sort %s; limit %d; offset %d;',
            self::DISCOVER_FIELDS,
            implode(' & ', $where),
            $order,
            $perPage,
            ($page - 1) * $perPage,
        );

        $response = Http::withHeaders([
            'Client-ID' => $this->clientId,
            'Authorization' => 'Bearer '.$this->auth->token(),
        ])->withBody($query, 'text/plain')->post(self::GAMES_URL);

        if ($response->failed()) {
            throw new RuntimeException('IGDB games request failed: '.$response->status());
        }

        $results = $response->json();

        return is_array($results) ? $results : [];
    }

    /**
     * IGDB `similar_games` for one seed game, in discover hit record shape
     * (pre-mapping). Empty when IGDB has no similar-games data for the id.
     *
     * @return list<array<string, mixed>>
     */
    public function similarGames(int $igdbId): array
    {
        $this->throttle();

        $query = sprintf(
            'fields %s; where id = %d; limit 1;',
            self::SIMILAR_FIELDS,
            $igdbId,
        );

        $response = Http::withHeaders([
            'Client-ID' => $this->clientId,
            'Authorization' => 'Bearer '.$this->auth->token(),
        ])->withBody($query, 'text/plain')->post(self::GAMES_URL);

        if ($response->failed()) {
            throw new RuntimeException('IGDB games request failed: '.$response->status());
        }

        $records = $response->json('0.similar_games');

        return is_array($records) ? $records : [];
    }

    /**
     * IGDB `franchises` (each with its full `games` list) for one owned
     * game. Empty when IGDB has no franchise data for the id.
     *
     * @return list<array<string, mixed>>
     */
    public function franchisesFor(int $igdbId): array
    {
        $this->throttle();

        $query = sprintf(
            'fields %s; where id = %d; limit 1;',
            self::FRANCHISE_FIELDS,
            $igdbId,
        );

        $response = Http::withHeaders([
            'Client-ID' => $this->clientId,
            'Authorization' => 'Bearer '.$this->auth->token(),
        ])->withBody($query, 'text/plain')->post(self::GAMES_URL);

        if ($response->failed()) {
            throw new RuntimeException('IGDB games request failed: '.$response->status());
        }

        $franchises = $response->json('0.franchises');

        return is_array($franchises) ? $franchises : [];
    }

    /**
     * T19: catalogue games releasing within a date window, optionally
     * restricted to a set of genre ids. `genres = (a,b,c)` is IGDB's
     * apicalypse "any of" syntax for array reference fields — matches a
     * game whose genres intersect the given ids, not an exact-set match.
     *
     * @param  list<int>  $genreIds
     * @return list<array<string, mixed>>
     */
    public function upcomingGames(array $genreIds, int $fromTimestamp, int $toTimestamp, int $limit = 20): array
    {
        $this->throttle();

        $where = [
            "first_release_date >= {$fromTimestamp}",
            "first_release_date <= {$toTimestamp}",
        ];

        if ($genreIds !== []) {
            $where[] = 'genres = ('.implode(',', $genreIds).')';
        }

        $query = sprintf(
            'fields %s; where %s; sort first_release_date asc; limit %d;',
            self::UPCOMING_FIELDS,
            implode(' & ', $where),
            $limit,
        );

        $response = Http::withHeaders([
            'Client-ID' => $this->clientId,
            'Authorization' => 'Bearer '.$this->auth->token(),
        ])->withBody($query, 'text/plain')->post(self::GAMES_URL);

        if ($response->failed()) {
            throw new RuntimeException('IGDB games request failed: '.$response->status());
        }

        $results = $response->json();

        return is_array($results) ? $results : [];
    }

    /**
     * Full IGDB genre list (id + name) for browse genre filtering.
     *
     * @return list<array<string, mixed>>
     */
    public function genres(): array
    {
        $this->throttle();

        $response = Http::withHeaders([
            'Client-ID' => $this->clientId,
            'Authorization' => 'Bearer '.$this->auth->token(),
        ])->withBody('fields name; limit 100;', 'text/plain')->post(self::GENRES_URL);

        if ($response->failed()) {
            throw new RuntimeException('IGDB genres request failed: '.$response->status());
        }

        $results = $response->json();

        return is_array($results) ? $results : [];
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
            'fields %s; where id = %d; limit 1;',
            self::CANONICAL_FIELDS,
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
            'fields game; where uid = "%s" & external_game_source = %d; limit 1;',
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
            'fields uid; where game = %d & external_game_source = %d; limit 1;',
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
