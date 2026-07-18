<?php

namespace Tests\Feature\Library;

use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibraryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->withToken($this->user->createToken('t')->plainTextToken);
    }

    private function connection(string $platform, string $status = 'ok'): PlatformConnection
    {
        return PlatformConnection::factory()->create([
            'user_id' => $this->user->id,
            'platform' => $platform,
            'status' => $status,
        ]);
    }

    private function own(
        PlatformConnection $connection,
        Game $game,
        ?int $playtime = null,
        ?string $lastPlayed = null,
        string $added = '2026-01-01 00:00:00',
        ?string $deckStatus = null,
    ): OwnedGame {
        return OwnedGame::create([
            'user_id' => $connection->user_id,
            'platform_connection_id' => $connection->id,
            'game_id' => $game->id,
            'platform_game_id' => (string) fake()->unique()->numberBetween(1, 999999),
            'playtime_minutes' => $playtime,
            'last_played_at' => $lastPlayed,
            'added_at' => $added,
            'deck_status' => $deckStatus,
        ]);
    }

    private function game(string $title, array $attrs = []): Game
    {
        return Game::create(['title' => $title, ...$attrs]);
    }

    /** T40: attach a personal rating (user_game_meta) to an owned game. */
    private function rate(OwnedGame $owned, int $rating): void
    {
        \App\Models\UserGameMeta::create([
            'user_id' => $owned->user_id,
            'game_id' => $owned->game_id,
            'rating' => $rating,
        ]);
    }

    /**
     * V1: same game owned on two platforms is one library entry with both
     * platforms listed — dedupe happens at query time.
     */
    public function test_deduplicates_multi_platform_games(): void
    {
        $game = $this->game('Portal 2');
        $this->own($this->connection('steam'), $game, 1200);
        $this->own($this->connection('gog'), $game, 60);

        $response = $this->getJson('/api/library')->assertOk();

        $this->assertCount(1, $response->json());
        $entry = $response->json()[0];
        $platforms = array_column($entry['platforms'], 'platform');
        sort($platforms);
        $this->assertSame(['gog', 'steam'], $platforms);
        $this->assertSame(1260, $entry['total_playtime_minutes']);
    }

    /**
     * V12: null playtime is unknown, not zero — excluded from unplayed.
     */
    public function test_unplayed_filter_excludes_unknown_playtime(): void
    {
        $this->own($this->connection('steam'), $this->game('Played'), 500);
        $this->own($this->connection('gog'), $this->game('Unknown'), null);
        $this->own($this->connection('steam'), $this->game('Unplayed'), 0);

        $response = $this->getJson('/api/library?unplayed=1')->assertOk();

        $titles = array_column($response->json(), 'title');
        $this->assertSame(['Unplayed'], $titles);
    }

    public function test_unknown_playtime_stays_null_in_payload(): void
    {
        $this->own($this->connection('gog'), $this->game('Unknown'), null);

        $entry = $this->getJson('/api/library')->json()[0];

        $this->assertNull($entry['total_playtime_minutes']);
        $this->assertNull($entry['platforms'][0]['playtime_minutes']);
    }

    /**
     * V13: games from a disconnected connection stay in the library and
     * carry the disconnected status for UI badging.
     */
    public function test_disconnected_games_remain_with_status(): void
    {
        $this->own($this->connection('steam', 'disconnected'), $this->game('Portal 2'), 100);

        $response = $this->getJson('/api/library')->assertOk();

        $this->assertCount(1, $response->json());
        $this->assertSame('disconnected', $response->json()[0]['platforms'][0]['connection_status']);
    }

    public function test_filters_by_platform(): void
    {
        $this->own($this->connection('steam'), $this->game('Steam Game'), 10);
        $this->own($this->connection('gog'), $this->game('GOG Game'), 10);

        $titles = array_column($this->getJson('/api/library?platform=gog')->json(), 'title');

        $this->assertSame(['GOG Game'], $titles);
    }

    public function test_filters_by_genre(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Puzzler', ['genres' => ['Puzzle']]), 10);
        $this->own($steam, $this->game('Shooter', ['genres' => ['Shooter']]), 10);

        $titles = array_column($this->getJson('/api/library?genre=Puzzle')->json(), 'title');

        $this->assertSame(['Puzzler'], $titles);
    }

    public function test_filters_by_theme(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Dark One', ['themes' => ['Horror']]), 10);
        $this->own($steam, $this->game('Light One', ['themes' => ['Comedy']]), 10);

        $titles = array_column($this->getJson('/api/library?theme=Horror')->json(), 'title');

        $this->assertSame(['Dark One'], $titles);
    }

    public function test_filters_by_keyword(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Pixel One', ['keywords' => ['pixel-art']]), 10);
        $this->own($steam, $this->game('Realistic One', ['keywords' => ['photorealistic']]), 10);

        $titles = array_column($this->getJson('/api/library?keyword=pixel-art')->json(), 'title');

        $this->assertSame(['Pixel One'], $titles);
    }

    public function test_filters_by_game_mode(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Solo One', ['game_modes' => ['Single player']]), 10);
        $this->own($steam, $this->game('Party One', ['game_modes' => ['Multiplayer']]), 10);

        $titles = array_column($this->getJson('/api/library?game_mode=Multiplayer')->json(), 'title');

        $this->assertSame(['Party One'], $titles);
    }

    public function test_filters_by_playtime_range(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Short'), 30);
        $this->own($steam, $this->game('Medium'), 300);
        $this->own($steam, $this->game('Long'), 3000);
        $this->own($steam, $this->game('Unknown'), null);

        $titles = array_column(
            $this->getJson('/api/library?playtime_min=100&playtime_max=1000')->json(),
            'title',
        );

        $this->assertSame(['Medium'], $titles);
    }

    public function test_sorts_alpha_by_default(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Zelda-like'), 10);
        $this->own($steam, $this->game('Axiom Verge'), 10);

        $titles = array_column($this->getJson('/api/library')->json(), 'title');

        $this->assertSame(['Axiom Verge', 'Zelda-like'], $titles);
    }

    public function test_sorts_by_playtime_desc_nulls_last(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Short'), 30);
        $this->own($steam, $this->game('Long'), 3000);
        $this->own($steam, $this->game('Unknown'), null);

        $titles = array_column(
            $this->getJson('/api/library?sort=playtime&order=desc')->json(),
            'title',
        );

        $this->assertSame(['Long', 'Short', 'Unknown'], $titles);
    }

    public function test_sorts_by_last_played_desc_nulls_last(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Recent'), 10, '2026-07-01 10:00:00');
        $this->own($steam, $this->game('Older'), 10, '2026-01-01 10:00:00');
        $this->own($steam, $this->game('Never'), 10, null);

        $titles = array_column(
            $this->getJson('/api/library?sort=last_played&order=desc')->json(),
            'title',
        );

        $this->assertSame(['Recent', 'Older', 'Never'], $titles);
    }

    public function test_sorts_by_added_date(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Newest'), 10, null, '2026-06-01 00:00:00');
        $this->own($steam, $this->game('Oldest'), 10, null, '2025-01-01 00:00:00');

        $titles = array_column(
            $this->getJson('/api/library?sort=added&order=desc')->json(),
            'title',
        );

        $this->assertSame(['Newest', 'Oldest'], $titles);
    }

    public function test_rejects_invalid_sort(): void
    {
        $this->getJson('/api/library?sort=bogus')->assertUnprocessable();
    }

    public function test_does_not_leak_other_users_games(): void
    {
        $other = PlatformConnection::factory()->create();
        $this->own($other, $this->game('Not Mine'), 10);

        $this->assertCount(0, $this->getJson('/api/library')->json());
    }

    public function test_requires_auth(): void
    {
        $this->withHeaders(['Authorization' => ''])
            ->getJson('/api/library')
            ->assertUnauthorized();
    }

    /**
     * I.api T24: show returns the same entry shape as the list, for one game.
     */
    public function test_show_returns_single_entry(): void
    {
        $game = $this->game('Portal 2', ['themes' => ['Sci-fi']]);
        $this->own($this->connection('steam'), $game, 1200);
        $this->own($this->connection('gog'), $game, 60);

        $response = $this->getJson("/api/library/{$game->id}")->assertOk();

        $this->assertSame('Portal 2', $response->json('title'));
        $this->assertSame(1260, $response->json('total_playtime_minutes'));
        $platforms = array_column($response->json('platforms'), 'platform');
        sort($platforms);
        $this->assertSame(['gog', 'steam'], $platforms);
    }

    public function test_show_404_when_not_owned(): void
    {
        $game = $this->game('Not Mine');

        $this->getJson("/api/library/{$game->id}")->assertNotFound();
    }

    public function test_show_404_for_other_users_game(): void
    {
        $other = PlatformConnection::factory()->create();
        $game = $this->game('Theirs');
        $this->own($other, $game, 10);

        $this->getJson("/api/library/{$game->id}")->assertNotFound();
    }

    public function test_show_requires_auth(): void
    {
        $game = $this->game('Portal 2');
        $this->own($this->connection('steam'), $game, 10);

        $this->withHeaders(['Authorization' => ''])
            ->getJson("/api/library/{$game->id}")
            ->assertUnauthorized();
    }

    /**
     * V28: hidden games excluded from /api/library by default.
     */
    public function test_hidden_games_excluded_by_default(): void
    {
        $hidden = $this->game('Hidden Game');
        $this->own($this->connection('steam'), $hidden, 10);
        \App\Models\UserGameMeta::create([
            'user_id' => $this->user->id,
            'game_id' => $hidden->id,
            'hidden' => true,
        ]);
        $this->own($this->connection('steam'), $this->game('Visible Game'), 10);

        $titles = array_column($this->getJson('/api/library')->assertOk()->json(), 'title');

        $this->assertSame(['Visible Game'], $titles);
    }

    /**
     * V28: include_hidden=1 reveals them.
     */
    public function test_include_hidden_reveals_hidden_games(): void
    {
        $hidden = $this->game('Hidden Game');
        $this->own($this->connection('steam'), $hidden, 10);
        \App\Models\UserGameMeta::create([
            'user_id' => $this->user->id,
            'game_id' => $hidden->id,
            'hidden' => true,
        ]);

        $titles = array_column(
            $this->getJson('/api/library?include_hidden=1')->assertOk()->json(),
            'title',
        );

        $this->assertSame(['Hidden Game'], $titles);
    }

    /**
     * T26/V31: deck_status rides on the platform entry, Steam-only.
     */
    public function test_platform_entry_carries_deck_status(): void
    {
        $this->own($this->connection('steam'), $this->game('Portal 2'), 10, deckStatus: 'verified');
        $this->own($this->connection('gog'), $this->game('Untested'), 10);

        $entries = collect($this->getJson('/api/library')->assertOk()->json())->keyBy('title');

        $this->assertSame('verified', $entries['Portal 2']['platforms'][0]['deck_status']);
        $this->assertNull($entries['Untested']['platforms'][0]['deck_status']);
    }

    /**
     * T26: matches any owning platform row in the selected set; null
     * (never checked) never matches.
     */
    public function test_filters_by_deck_status(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Verified'), 10, deckStatus: 'verified');
        $this->own($steam, $this->game('Unsupported'), 10, deckStatus: 'unsupported');
        $this->own($steam, $this->game('Never Checked'), 10);

        $titles = array_column(
            $this->getJson('/api/library?deck_status[]=verified&deck_status[]=playable')->json(),
            'title',
        );

        $this->assertSame(['Verified'], $titles);
    }

    /**
     * T27/V33: esrb_rating rides the entry; null = unrated.
     */
    public function test_entry_carries_esrb_rating(): void
    {
        $this->own($this->connection('steam'), $this->game('Rated', ['esrb_rating' => 'M']), 10);
        $this->own($this->connection('steam'), $this->game('Unrated'), 10);

        $entries = collect($this->getJson('/api/library')->assertOk()->json())->keyBy('title');

        $this->assertSame('M', $entries['Rated']['esrb_rating']);
        $this->assertNull($entries['Unrated']['esrb_rating']);
    }

    // T36: multi-select, mirrors deck_status[].
    public function test_filters_by_esrb(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Mature', ['esrb_rating' => 'M']), 10);
        $this->own($steam, $this->game('Everyone', ['esrb_rating' => 'E']), 10);

        $titles = array_column($this->getJson('/api/library?esrb[]=M')->json(), 'title');

        $this->assertSame(['Mature'], $titles);
    }

    /**
     * T36/V33: `none` sentinel matches unrated (esrb_rating null) at the
     * query layer only — storage stays null.
     */
    public function test_filters_by_multiple_esrb_including_none(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Mature', ['esrb_rating' => 'M']), 10);
        $this->own($steam, $this->game('Everyone', ['esrb_rating' => 'E']), 10);
        $this->own($steam, $this->game('Unrated'), 10);

        $titles = array_column(
            $this->getJson('/api/library?esrb[]=E&esrb[]=none')->json(),
            'title',
        );
        sort($titles);

        $this->assertSame(['Everyone', 'Unrated'], $titles);
    }

    // T40: personal rating multi-select, mirrors esrb[].
    public function test_filters_by_rating(): void
    {
        $steam = $this->connection('steam');
        $this->rate($this->own($steam, $this->game('Three Star'), 10), 3);
        $this->rate($this->own($steam, $this->game('Five Star'), 10), 5);

        $titles = array_column($this->getJson('/api/library?rating[]=3')->assertOk()->json(), 'title');

        $this->assertSame(['Three Star'], $titles);
    }

    /**
     * T40: `none` sentinel matches unrated (rating null) at the query layer;
     * combines with numeric selections.
     */
    public function test_filters_by_rating_including_none(): void
    {
        $steam = $this->connection('steam');
        $this->rate($this->own($steam, $this->game('Five Star'), 10), 5);
        $this->rate($this->own($steam, $this->game('Three Star'), 10), 3);
        $this->own($steam, $this->game('Unrated'), 10);

        $titles = array_column(
            $this->getJson('/api/library?rating[]=5&rating[]=none')->assertOk()->json(),
            'title',
        );
        sort($titles);

        $this->assertSame(['Five Star', 'Unrated'], $titles);
    }

    /**
     * V43/B10: esrb_rating stores IGDB label strings verbatim ("E10+" ≠
     * "E10") — every facet-emitted value must be accepted by the filter
     * param and match by equality. No hardcoded label enum.
     */
    public function test_v43_facet_esrb_values_round_trip_to_filter(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Everyone Tenplus', ['esrb_rating' => 'E10+']), 10);
        $this->own($steam, $this->game('Unrated'), 10);

        $facets = $this->getJson('/api/library/facets')->assertOk()->json();
        $this->assertSame(['E10+', 'none'], $facets['esrb_ratings']);

        foreach ($facets['esrb_ratings'] as $value) {
            $this->getJson('/api/library?esrb[]='.urlencode($value))->assertOk();
        }

        $titles = array_column(
            $this->getJson('/api/library?esrb[]='.urlencode('E10+'))->json(),
            'title',
        );
        $this->assertSame(['Everyone Tenplus'], $titles);
    }

    // V43: unknown label matches nothing — 200 empty, ⊥ 422.
    public function test_unknown_esrb_value_matches_nothing(): void
    {
        $this->own($this->connection('steam'), $this->game('Mature', ['esrb_rating' => 'M']), 10);

        $this->getJson('/api/library?esrb[]=X')->assertOk()->assertExactJson([]);
    }

    /**
     * T27/V32: null (best-effort miss) matches neither an explicit true nor
     * false query.
     */
    public function test_filters_by_multiplayer_flags(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Coop Game', ['multiplayer' => true, 'coop' => true, 'local_coop' => true]), 10);
        $this->own($steam, $this->game('Solo Game', ['multiplayer' => false, 'coop' => false]), 10);
        $this->own($steam, $this->game('Unchecked Game'), 10);

        $this->assertSame(
            ['Coop Game'],
            array_column($this->getJson('/api/library?multiplayer=1')->json(), 'title'),
        );
        $this->assertSame(
            ['Coop Game'],
            array_column($this->getJson('/api/library?coop=1')->json(), 'title'),
        );
        $this->assertSame(
            ['Coop Game'],
            array_column($this->getJson('/api/library?local_coop=1')->json(), 'title'),
        );
        $this->assertSame(
            ['Solo Game'],
            array_column($this->getJson('/api/library?multiplayer=0')->json(), 'title'),
        );
    }

    /**
     * T80/V76: null (best-effort miss) matches neither an explicit true nor
     * false query, mirrors multiplayer flags.
     */
    public function test_filters_by_vr(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('VR Game', ['vr_supported' => true]), 10);
        $this->own($steam, $this->game('Flatscreen Game', ['vr_supported' => false]), 10);
        $this->own($steam, $this->game('Unchecked Game'), 10);

        $this->assertSame(
            ['VR Game'],
            array_column($this->getJson('/api/library?vr=1')->json(), 'title'),
        );
        $this->assertSame(
            ['Flatscreen Game'],
            array_column($this->getJson('/api/library?vr=0')->json(), 'title'),
        );
    }

    /**
     * T28: title substring, case-insensitive.
     */
    public function test_filters_by_title_search(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Portal 2'), 10);
        $this->own($steam, $this->game('Half-Life'), 10);

        $titles = array_column($this->getJson('/api/library?q=portal')->json(), 'title');

        $this->assertSame(['Portal 2'], $titles);
    }

    /**
     * T28: multi-select via comma string — same convention as `tags`,
     * single value still behaves exactly as before.
     */
    public function test_filters_by_multiple_genres(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Puzzler', ['genres' => ['Puzzle']]), 10);
        $this->own($steam, $this->game('Shooter', ['genres' => ['Shooter']]), 10);
        $this->own($steam, $this->game('Racer', ['genres' => ['Racing']]), 10);

        $titles = array_column(
            $this->getJson('/api/library?genre=Puzzle,Shooter')->json(),
            'title',
        );
        sort($titles);

        $this->assertSame(['Puzzler', 'Shooter'], $titles);
    }

    public function test_filters_by_multiple_platforms(): void
    {
        $this->own($this->connection('steam'), $this->game('Steam Game'), 10);
        $this->own($this->connection('gog'), $this->game('GOG Game'), 10);
        $this->own($this->connection('manual'), $this->game('Manual Game'), 10);

        $titles = array_column(
            $this->getJson('/api/library?platform=steam,gog')->json(),
            'title',
        );
        sort($titles);

        $this->assertSame(['GOG Game', 'Steam Game'], $titles);
    }

    /**
     * T28/V28/V36: facets exclude hidden games and reflect the caller's
     * full library vocabulary.
     */
    public function test_facets_returns_distinct_values_excluding_hidden(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Puzzler', ['genres' => ['Puzzle'], 'themes' => ['Comedy']]), 10);
        $this->own($this->connection('gog'), $this->game('Racer', ['genres' => ['Racing']]), 10);

        $hidden = $this->game('Hidden Horror', ['genres' => ['Horror']]);
        $this->own($steam, $hidden, 10);
        \App\Models\UserGameMeta::create([
            'user_id' => $this->user->id,
            'game_id' => $hidden->id,
            'hidden' => true,
        ]);

        $facets = $this->getJson('/api/library/facets')->assertOk()->json();

        $this->assertSame(['Puzzle', 'Racing'], $facets['genres']);
        $this->assertSame(['Comedy'], $facets['themes']);
        $this->assertSame(['gog', 'steam'], $facets['platforms']);
    }

    /**
     * T36/V28/V33: esrb_ratings facet = distinct in-library values sorted,
     * `none` appended when unrated (null) games exist; hidden games'
     * ratings excluded.
     */
    public function test_facets_esrb_ratings_with_none_sentinel(): void
    {
        $steam = $this->connection('steam');
        $this->own($steam, $this->game('Mature', ['esrb_rating' => 'M']), 10);
        $this->own($steam, $this->game('Everyone', ['esrb_rating' => 'E']), 10);
        $this->own($steam, $this->game('Unrated'), 10);

        $hidden = $this->game('Hidden AO', ['esrb_rating' => 'AO']);
        $this->own($steam, $hidden, 10);
        \App\Models\UserGameMeta::create([
            'user_id' => $this->user->id,
            'game_id' => $hidden->id,
            'hidden' => true,
        ]);

        $facets = $this->getJson('/api/library/facets')->assertOk()->json();

        $this->assertSame(['E', 'M', 'none'], $facets['esrb_ratings']);
    }

    public function test_facets_esrb_ratings_omit_none_when_all_rated(): void
    {
        $this->own($this->connection('steam'), $this->game('Mature', ['esrb_rating' => 'M']), 10);

        $facets = $this->getJson('/api/library/facets')->assertOk()->json();

        $this->assertSame(['M'], $facets['esrb_ratings']);
    }

    public function test_facets_requires_auth(): void
    {
        $this->withHeaders(['Authorization' => ''])
            ->getJson('/api/library/facets')
            ->assertUnauthorized();
    }
}
