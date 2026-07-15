<?php

namespace Tests\Feature\Discover;

use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Models\UserGameMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DiscoverSimilarTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->withToken($this->user->createToken('api')->plainTextToken);
    }

    private function fakeIgdbSimilar(int $seedIgdbId, array $similar): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([
                ['id' => $seedIgdbId, 'similar_games' => $similar],
            ]),
        ]);
    }

    private function own(
        int $igdbId,
        string $title,
        ?int $playtime = null,
        ?int $rating = null,
    ): Game {
        $game = Game::create(['igdb_id' => $igdbId, 'title' => $title]);
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
            'playtime_minutes' => $playtime,
            'added_at' => now(),
        ]);

        if ($rating !== null) {
            UserGameMeta::create([
                'user_id' => $this->user->id,
                'game_id' => $game->id,
                'rating' => $rating,
            ]);
        }

        return $game;
    }

    public function test_returns_rail_with_seed_and_similar_hits(): void
    {
        $this->own(292030, 'The Witcher 3: Wild Hunt', 1200);
        $this->fakeIgdbSimilar(292030, [
            [
                'id' => 119388,
                'name' => 'Hades II',
                'cover' => ['url' => '//images.igdb.com/hades2.jpg'],
                'genres' => [['name' => 'Roguelike']],
                'first_release_date' => 1715558400,
                'total_rating' => 92.4,
            ],
        ]);

        $rails = $this->getJson('/api/discover/similar')->assertOk()->json();

        $this->assertCount(1, $rails);
        $this->assertSame('The Witcher 3: Wild Hunt', $rails[0]['seed']['title']);
        $this->assertSame(292030, $rails[0]['seed']['igdb_id']);
        $this->assertCount(1, $rails[0]['similar']);
        $this->assertSame('Hades II', $rails[0]['similar'][0]['title']);
        $this->assertFalse($rails[0]['similar'][0]['in_library']);
    }

    /**
     * §I: seeds = top-playtime/rated owned games w/ igdb_id.
     */
    public function test_seeds_prioritize_rated_over_playtime(): void
    {
        $this->own(1, 'Rated Low Playtime', 10, 5);
        $this->own(2, 'Unrated High Playtime', 9000);
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => function (Request $request) {
                $body = $request->body();
                $seedId = str_contains($body, 'where id = 1;') ? 1 : 2;

                return Http::response([
                    ['id' => $seedId, 'similar_games' => [
                        ['id' => 999, 'name' => 'Filler'],
                    ]],
                ]);
            },
        ]);

        $rails = $this->getJson('/api/discover/similar')->assertOk()->json();

        $this->assertSame('Rated Low Playtime', $rails[0]['seed']['title']);
        $this->assertSame('Unrated High Playtime', $rails[1]['seed']['title']);
    }

    /**
     * V4: similar_games payload cached globally by igdb_id.
     */
    public function test_caches_similar_games_globally(): void
    {
        $this->own(292030, 'The Witcher 3: Wild Hunt', 1200);
        $this->fakeIgdbSimilar(292030, [
            ['id' => 119388, 'name' => 'Hades II'],
        ]);

        $this->getJson('/api/discover/similar')->assertOk();

        $other = User::factory()->create();
        $this->app['auth']->forgetGuards();
        $this->withToken($other->createToken('api')->plainTextToken);
        $otherConnection = PlatformConnection::factory()->create([
            'user_id' => $other->id,
            'platform' => 'steam',
            'status' => 'ok',
        ]);
        OwnedGame::create([
            'user_id' => $other->id,
            'platform_connection_id' => $otherConnection->id,
            'game_id' => Game::where('igdb_id', 292030)->firstOrFail()->id,
            'platform_game_id' => '1',
            'playtime_minutes' => 100,
            'added_at' => now(),
        ]);

        $this->getJson('/api/discover/similar')->assertOk();

        $igdbCalls = Http::recorded(
            fn (Request $request) => str_contains($request->url(), 'api.igdb.com/v4/games'),
        );
        $this->assertCount(1, $igdbCalls);
    }

    /**
     * V20: overlay flags computed per request against caller's own library.
     */
    public function test_owned_similar_hit_flagged_in_library(): void
    {
        $this->own(292030, 'The Witcher 3: Wild Hunt', 1200);
        $this->own(119388, 'Hades II', 50);
        $this->fakeIgdbSimilar(292030, [
            ['id' => 119388, 'name' => 'Hades II'],
        ]);

        $rails = $this->getJson('/api/discover/similar')->assertOk()->json();

        $this->assertTrue($rails[0]['similar'][0]['in_library']);
    }

    public function test_seed_excluded_from_its_own_similar_list(): void
    {
        $this->own(292030, 'The Witcher 3: Wild Hunt', 1200);
        $this->fakeIgdbSimilar(292030, [
            ['id' => 292030, 'name' => 'The Witcher 3: Wild Hunt'],
        ]);

        $rails = $this->getJson('/api/discover/similar')->assertOk()->json();

        $this->assertCount(0, $rails);
    }

    public function test_no_rails_when_library_empty(): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
        ]);

        $rails = $this->getJson('/api/discover/similar')->assertOk()->json();

        $this->assertSame([], $rails);
    }

    public function test_requires_auth(): void
    {
        $this->withHeaders(['Authorization' => ''])
            ->getJson('/api/discover/similar')
            ->assertUnauthorized();
    }
}
