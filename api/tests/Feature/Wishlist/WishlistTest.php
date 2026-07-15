<?php

namespace Tests\Feature\Wishlist;

use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WishlistTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->withToken($this->user->createToken('api')->plainTextToken);
    }

    private function fakeIgdbGame(int $id = 119388, string $name = 'Hades II'): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([
                ['id' => $id, 'name' => $name, 'genres' => [['name' => 'Roguelike']]],
            ]),
            'api.igdb.com/v4/game_time_to_beats' => Http::response([]),
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
            'platform_game_id' => '1145360',
            'playtime_minutes' => 60,
            'added_at' => now(),
        ]);

        return $game;
    }

    public function test_add_to_wishlist_creates_game_and_row(): void
    {
        $this->fakeIgdbGame();

        $this->postJson('/api/wishlist', ['igdb_id' => 119388])
            ->assertCreated()
            ->assertJsonPath('title', 'Hades II');

        $this->assertDatabaseHas('games', ['igdb_id' => 119388]);
        $this->assertDatabaseCount('wishlist_items', 1);
    }

    public function test_duplicate_add_is_noop(): void
    {
        $this->fakeIgdbGame();

        $this->postJson('/api/wishlist', ['igdb_id' => 119388])->assertCreated();
        $this->postJson('/api/wishlist', ['igdb_id' => 119388])->assertOk();

        $this->assertDatabaseCount('wishlist_items', 1);
    }

    /**
     * V21: owned games can't be wishlisted — 200 no-op flagged in_library.
     */
    public function test_owned_game_add_is_noop_with_flag(): void
    {
        $this->fakeIgdbGame(1942, 'The Witcher 3: Wild Hunt');
        $this->ownGame(1942, 'The Witcher 3: Wild Hunt');

        $this->postJson('/api/wishlist', ['igdb_id' => 1942])
            ->assertOk()
            ->assertJsonPath('in_library', true);

        $this->assertDatabaseCount('wishlist_items', 0);
    }

    public function test_list_returns_game_shape_with_added_at(): void
    {
        $this->fakeIgdbGame();
        $this->postJson('/api/wishlist', ['igdb_id' => 119388])->assertCreated();

        $items = $this->getJson('/api/wishlist')->assertOk()->json();

        $this->assertCount(1, $items);
        $this->assertSame('Hades II', $items[0]['title']);
        $this->assertSame(119388, $items[0]['igdb_id']);
        $this->assertArrayHasKey('added_at', $items[0]);
        $this->assertArrayHasKey('game_id', $items[0]);
    }

    /**
     * V21 (amended T38/V42): wishlist rows now join the /api/library union
     * view with library_status=wishlist — the exclusion narrowed to the
     * stats layer only. Default library sort surfaces them alongside owned.
     */
    public function test_wishlist_games_appear_in_library_as_wishlist_status(): void
    {
        $this->fakeIgdbGame();
        $this->postJson('/api/wishlist', ['igdb_id' => 119388])->assertCreated();

        $entries = $this->getJson('/api/library')->assertOk()->json();

        $this->assertCount(1, $entries);
        $this->assertSame('wishlist', $entries[0]['library_status']);
        $this->assertSame([], $entries[0]['platforms']);
        $this->assertNull($entries[0]['total_playtime_minutes']);
    }

    /**
     * V21: promote — manual add removes the wishlist row.
     */
    public function test_manual_add_promotes_and_clears_wishlist_row(): void
    {
        $this->fakeIgdbGame();
        $this->postJson('/api/wishlist', ['igdb_id' => 119388])->assertCreated();

        $this->postJson('/api/library', ['igdb_id' => 119388])->assertCreated();

        $this->assertDatabaseCount('wishlist_items', 0);
        $this->assertDatabaseCount('owned_games', 1);
    }

    public function test_remove_from_wishlist(): void
    {
        $this->fakeIgdbGame();
        $this->postJson('/api/wishlist', ['igdb_id' => 119388])->assertCreated();
        $gameId = Game::where('igdb_id', 119388)->firstOrFail()->id;

        $this->deleteJson("/api/wishlist/{$gameId}")->assertNoContent();
        $this->assertDatabaseCount('wishlist_items', 0);

        $this->deleteJson("/api/wishlist/{$gameId}")->assertNotFound();
    }

    public function test_wishlist_private_per_user(): void
    {
        $this->fakeIgdbGame();
        $this->postJson('/api/wishlist', ['igdb_id' => 119388])->assertCreated();

        $other = User::factory()->create();
        $this->app['auth']->forgetGuards();
        $this->withToken($other->createToken('api')->plainTextToken);

        $this->assertSame([], $this->getJson('/api/wishlist')->assertOk()->json());
    }
}
