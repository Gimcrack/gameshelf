<?php

namespace Tests\Feature\Discover;

use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DiscoverUpcomingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->withToken($this->user->createToken('api')->plainTextToken);
    }

    private function fakeIgdb(array $genres, array $upcoming): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/genres' => Http::response($genres),
            'api.igdb.com/v4/games' => Http::response($upcoming),
        ]);
    }

    private function own(string $title, array $genres): Game
    {
        $game = Game::create(['title' => $title, 'genres' => $genres]);
        $connection = PlatformConnection::factory()->create([
            'user_id' => $this->user->id,
            'platform' => 'steam',
            'status' => 'ok',
        ]);
        OwnedGame::create([
            'user_id' => $this->user->id,
            'platform_connection_id' => $connection->id,
            'game_id' => $game->id,
            'platform_game_id' => (string) fake()->unique()->numberBetween(1, 999999),
            'added_at' => now(),
        ]);

        return $game;
    }

    public function test_returns_hits_for_top_owned_genre(): void
    {
        $this->own('RPG Game', ['RPG']);
        $this->fakeIgdb(
            [['id' => 12, 'name' => 'RPG']],
            [[
                'id' => 555,
                'name' => 'Upcoming RPG',
                'genres' => [['name' => 'RPG']],
                'first_release_date' => now()->addMonths(2)->timestamp,
                'total_rating' => 80.0,
            ]],
        );

        $hits = $this->getJson('/api/discover/upcoming')->assertOk()->json();

        $this->assertCount(1, $hits);
        $this->assertSame('Upcoming RPG', $hits[0]['title']);
        $this->assertFalse($hits[0]['in_library']);
    }

    public function test_query_filters_by_resolved_genre_id_and_date_window(): void
    {
        $this->own('RPG Game', ['RPG']);
        $this->fakeIgdb([['id' => 12, 'name' => 'RPG']], []);

        $this->getJson('/api/discover/upcoming')->assertOk();

        $gamesCall = Http::recorded(
            fn (Request $request) => str_contains($request->url(), 'api.igdb.com/v4/games'),
        )->first()[0];

        $this->assertStringContainsString('genres = (12)', $gamesCall->body());
        $this->assertStringContainsString('first_release_date >=', $gamesCall->body());
        $this->assertStringContainsString('sort first_release_date asc', $gamesCall->body());
    }

    /**
     * §I: no owned genres yet → no unfiltered firehose, empty rail.
     */
    public function test_empty_library_returns_no_hits(): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
        ]);

        $hits = $this->getJson('/api/discover/upcoming')->assertOk()->json();

        $this->assertSame([], $hits);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.igdb.com/v4/games'));
    }

    /**
     * V4: same resolved genre set → cached, second caller doesn't re-hit IGDB.
     */
    public function test_caches_upcoming_query_globally(): void
    {
        $this->own('RPG Game', ['RPG']);
        $this->fakeIgdb([['id' => 12, 'name' => 'RPG']], [[
            'id' => 555,
            'name' => 'Upcoming RPG',
            'first_release_date' => now()->addMonths(2)->timestamp,
        ]]);

        $this->getJson('/api/discover/upcoming')->assertOk();

        $other = User::factory()->create();
        $this->app['auth']->forgetGuards();
        $this->withToken($other->createToken('api')->plainTextToken);
        $this->own('Other RPG', ['RPG']);

        $this->getJson('/api/discover/upcoming')->assertOk();

        $igdbCalls = Http::recorded(
            fn (Request $request) => str_contains($request->url(), 'api.igdb.com/v4/games'),
        );
        $this->assertCount(1, $igdbCalls);
    }

    /**
     * V20: overlay flags computed per request against caller's own library.
     */
    public function test_owned_hit_flagged_in_library(): void
    {
        $this->own('RPG Game', ['RPG']);
        $this->own('Upcoming RPG', ['RPG']);
        Game::where('title', 'Upcoming RPG')->update(['igdb_id' => 555]);
        $this->fakeIgdb([['id' => 12, 'name' => 'RPG']], [[
            'id' => 555,
            'name' => 'Upcoming RPG',
            'first_release_date' => now()->addMonths(2)->timestamp,
        ]]);

        $hits = $this->getJson('/api/discover/upcoming')->assertOk()->json();

        $this->assertTrue($hits[0]['in_library']);
    }

    public function test_requires_auth(): void
    {
        $this->withHeaders(['Authorization' => ''])
            ->getJson('/api/discover/upcoming')
            ->assertUnauthorized();
    }
}
