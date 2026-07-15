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

class DiscoverFranchisesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->withToken($this->user->createToken('api')->plainTextToken);
    }

    private function fakeIgdbFranchises(int $seedIgdbId, array $franchises): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([
                ['id' => $seedIgdbId, 'franchises' => $franchises],
            ]),
        ]);
    }

    private function own(int $igdbId, string $title): Game
    {
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
            'added_at' => now(),
        ]);

        return $game;
    }

    public function test_returns_rail_with_owned_and_missing_split(): void
    {
        $this->own(1942, 'The Witcher 3: Wild Hunt');
        $this->fakeIgdbFranchises(1942, [
            [
                'id' => 5,
                'name' => 'The Witcher',
                'games' => [
                    ['id' => 1942, 'name' => 'The Witcher 3: Wild Hunt'],
                    [
                        'id' => 1943,
                        'name' => 'The Witcher 2: Assassins of Kings',
                        'cover' => ['url' => '//images.igdb.com/witcher2.jpg'],
                        'genres' => [['name' => 'RPG']],
                        'first_release_date' => 1305849600,
                        'total_rating' => 85.0,
                    ],
                ],
            ],
        ]);

        $rails = $this->getJson('/api/discover/franchises')->assertOk()->json();

        $this->assertCount(1, $rails);
        $this->assertSame('The Witcher', $rails[0]['franchise']);
        $this->assertSame([1942], array_column($rails[0]['owned'], 'igdb_id'));
        $this->assertSame([1943], array_column($rails[0]['missing'], 'igdb_id'));
        $this->assertFalse($rails[0]['missing'][0]['in_library']);
    }

    /**
     * Franchise fully owned → nothing to complete, rail dropped.
     */
    public function test_fully_owned_franchise_excluded(): void
    {
        $this->own(1942, 'The Witcher 3: Wild Hunt');
        $this->fakeIgdbFranchises(1942, [
            [
                'id' => 5,
                'name' => 'The Witcher',
                'games' => [
                    ['id' => 1942, 'name' => 'The Witcher 3: Wild Hunt'],
                ],
            ],
        ]);

        $rails = $this->getJson('/api/discover/franchises')->assertOk()->json();

        $this->assertSame([], $rails);
    }

    /**
     * V4: franchise payload cached globally by owned game's igdb_id.
     */
    public function test_caches_franchise_lookup_globally(): void
    {
        $this->own(1942, 'The Witcher 3: Wild Hunt');
        $this->fakeIgdbFranchises(1942, [
            ['id' => 5, 'name' => 'The Witcher', 'games' => [
                ['id' => 1942, 'name' => 'The Witcher 3: Wild Hunt'],
                ['id' => 1943, 'name' => 'The Witcher 2: Assassins of Kings'],
            ]],
        ]);

        $this->getJson('/api/discover/franchises')->assertOk();

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
            'game_id' => Game::where('igdb_id', 1942)->firstOrFail()->id,
            'platform_game_id' => '1',
            'added_at' => now(),
        ]);

        $this->getJson('/api/discover/franchises')->assertOk();

        $igdbCalls = Http::recorded(
            fn (Request $request) => str_contains($request->url(), 'api.igdb.com/v4/games'),
        );
        $this->assertCount(1, $igdbCalls);
    }

    /**
     * V20: missing hit's in_wishlist flag reflects the caller's wishlist.
     */
    public function test_missing_hit_flagged_in_wishlist(): void
    {
        $this->own(1942, 'The Witcher 3: Wild Hunt');
        $this->fakeIgdbFranchises(1942, [
            ['id' => 5, 'name' => 'The Witcher', 'games' => [
                ['id' => 1942, 'name' => 'The Witcher 3: Wild Hunt'],
                ['id' => 1943, 'name' => 'The Witcher 2: Assassins of Kings'],
            ]],
        ]);
        $missingGame = Game::create(['igdb_id' => 1943, 'title' => 'The Witcher 2: Assassins of Kings']);
        \App\Models\WishlistItem::create([
            'user_id' => $this->user->id,
            'game_id' => $missingGame->id,
            'added_at' => now(),
        ]);

        $rails = $this->getJson('/api/discover/franchises')->assertOk()->json();

        $this->assertTrue($rails[0]['missing'][0]['in_wishlist']);
    }

    /**
     * Two owned games in the same franchise → one rail, games deduped.
     */
    public function test_dedupes_franchise_across_multiple_owned_seeds(): void
    {
        $this->own(1942, 'The Witcher 3: Wild Hunt');
        $this->own(1943, 'The Witcher 2: Assassins of Kings');

        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => function (Request $request) {
                $body = $request->body();
                $seedId = str_contains($body, 'where id = 1942;') ? 1942 : 1943;

                return Http::response([
                    ['id' => $seedId, 'franchises' => [
                        ['id' => 5, 'name' => 'The Witcher', 'games' => [
                            ['id' => 1942, 'name' => 'The Witcher 3: Wild Hunt'],
                            ['id' => 1943, 'name' => 'The Witcher 2: Assassins of Kings'],
                            ['id' => 1944, 'name' => 'The Witcher'],
                        ]],
                    ]],
                ]);
            },
        ]);

        $rails = $this->getJson('/api/discover/franchises')->assertOk()->json();

        $this->assertCount(1, $rails);
        $this->assertCount(2, $rails[0]['owned']);
        $this->assertSame([1944], array_column($rails[0]['missing'], 'igdb_id'));
    }

    public function test_no_rails_when_library_empty(): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
        ]);

        $rails = $this->getJson('/api/discover/franchises')->assertOk()->json();

        $this->assertSame([], $rails);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.igdb.com/v4/games'));
    }

    public function test_requires_auth(): void
    {
        $this->withHeaders(['Authorization' => ''])
            ->getJson('/api/discover/franchises')
            ->assertUnauthorized();
    }
}
