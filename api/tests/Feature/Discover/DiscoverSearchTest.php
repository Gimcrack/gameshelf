<?php

namespace Tests\Feature\Discover;

use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DiscoverSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->withToken($this->user->createToken('api')->plainTextToken);
    }

    private function fakeIgdbSearch(): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([
                [
                    'id' => 119388,
                    'name' => 'Hades II',
                    'cover' => ['url' => '//images.igdb.com/hades2.jpg'],
                    'genres' => [['name' => 'Roguelike']],
                    'first_release_date' => 1715558400,
                    'total_rating' => 92.4,
                ],
                ['id' => 1942, 'name' => 'The Witcher 3: Wild Hunt'],
            ]),
        ]);
    }

    private function ownGame(int $igdbId, string $title): Game
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
            'platform_game_id' => '292030',
            'playtime_minutes' => 60,
            'added_at' => now(),
        ]);

        return $game;
    }

    public function test_search_returns_hit_shape(): void
    {
        $this->fakeIgdbSearch();

        $hits = $this->getJson('/api/discover/search?q=hades')->assertOk()->json();

        $this->assertCount(2, $hits);
        $this->assertSame([
            'igdb_id' => 119388,
            'title' => 'Hades II',
            'cover_url' => '//images.igdb.com/hades2.jpg',
            'genres' => ['Roguelike'],
            'release_date' => '2024-05-13',
            'rating' => 92,
            'in_library' => false,
            'in_wishlist' => false,
        ], $hits[0]);
        $this->assertNull($hits[1]['rating']);
    }

    /**
     * V20: in_library computed vs caller's owned igdb_ids.
     */
    public function test_owned_game_flagged_in_library(): void
    {
        $this->fakeIgdbSearch();
        $this->ownGame(1942, 'The Witcher 3: Wild Hunt');

        $hits = $this->getJson('/api/discover/search?q=hades')->assertOk()->json();

        $this->assertFalse($hits[0]['in_library']);
        $this->assertTrue($hits[1]['in_library']);
    }

    /**
     * V20 (T17 extension): in_wishlist same per-request rule; suppressed
     * tombstones don't count.
     */
    public function test_wishlisted_game_flagged_suppressed_not(): void
    {
        $this->fakeIgdbSearch();
        $wished = Game::create(['igdb_id' => 119388, 'title' => 'Hades II']);
        $suppressed = Game::create(['igdb_id' => 1942, 'title' => 'The Witcher 3: Wild Hunt']);
        WishlistItem::create([
            'user_id' => $this->user->id,
            'game_id' => $wished->id,
            'added_at' => now(),
        ]);
        WishlistItem::create([
            'user_id' => $this->user->id,
            'game_id' => $suppressed->id,
            'added_at' => now(),
            'suppressed_at' => now(),
        ]);

        $hits = $this->getJson('/api/discover/search?q=hades')->assertOk()->json();

        $this->assertTrue($hits[0]['in_wishlist']);
        $this->assertFalse($hits[1]['in_wishlist']);
    }

    /**
     * V4 + V20: IGDB payload cached globally (one upstream call for two
     * users), ownership overlay computed per request.
     */
    public function test_cache_global_overlay_per_user(): void
    {
        $this->fakeIgdbSearch();
        $this->ownGame(1942, 'The Witcher 3: Wild Hunt');

        $first = $this->getJson('/api/discover/search?q=hades')->assertOk()->json();
        $this->assertTrue($first[1]['in_library']);

        $other = User::factory()->create();
        $this->app['auth']->forgetGuards();
        $this->withToken($other->createToken('api')->plainTextToken);

        $second = $this->getJson('/api/discover/search?q=hades')->assertOk()->json();
        $this->assertFalse($second[1]['in_library']);

        $igdbCalls = Http::recorded(
            fn (Request $request) => str_contains($request->url(), 'api.igdb.com/v4/games'),
        );
        $this->assertCount(1, $igdbCalls);
    }

    public function test_query_validated(): void
    {
        $this->fakeIgdbSearch();

        $this->getJson('/api/discover/search')->assertUnprocessable();
        $this->getJson('/api/discover/search?q=a')->assertUnprocessable();
    }
}
