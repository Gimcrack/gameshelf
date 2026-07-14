<?php

namespace Tests\Feature\Wishlist;

use App\Jobs\SyncWishlist;
use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WishlistSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->withToken($this->user->createToken('api')->plainTextToken);
    }

    private function steamConnection(): PlatformConnection
    {
        return PlatformConnection::factory()->create([
            'user_id' => $this->user->id,
            'platform' => 'steam',
            'external_account_id' => '76561197960287930',
            'status' => 'ok',
        ]);
    }

    private function gogConnection(): PlatformConnection
    {
        return PlatformConnection::factory()->create([
            'user_id' => $this->user->id,
            'platform' => 'gog',
            'external_account_id' => '48628349957132247',
            'status' => 'ok',
            'auth_token' => 'gog-access-token',
            'refresh_token' => 'gog-refresh-token',
            'token_expires_at' => now()->addHour(),
        ]);
    }

    /**
     * Baseline fakes: twitch auth + empty IGDB endpoints; override per test.
     *
     * @return array<string, mixed>
     */
    private function baseFakes(): array
    {
        return [
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/game_time_to_beats' => Http::response([]),
            'api.igdb.com/v4/external_games' => Http::response([]),
            'api.igdb.com/v4/games' => Http::response([]),
        ];
    }

    private function runSync(): void
    {
        (new SyncWishlist($this->user->id))->handle();
    }

    public function test_steam_wishlist_imported_with_igdb_match(): void
    {
        $this->steamConnection();
        Http::fake([
            ...$this->baseFakes(),
            'api.steampowered.com/IWishlistService/GetWishlist/*' => Http::response([
                'response' => ['items' => [['appid' => 1145360, 'priority' => 0]]],
            ]),
            // appid → igdb id 119388 via external_games (category 1 = steam).
            'api.igdb.com/v4/external_games' => Http::response([
                ['id' => 5, 'game' => 119388, 'uid' => '1145360', 'category' => 1],
            ]),
            'api.igdb.com/v4/games' => Http::response([
                ['id' => 119388, 'name' => 'Hades II', 'genres' => [['name' => 'Roguelike']]],
            ]),
        ]);

        $this->runSync();

        $this->assertDatabaseHas('games', ['igdb_id' => 119388, 'title' => 'Hades II']);
        $this->assertDatabaseHas('wishlist_items', [
            'user_id' => $this->user->id,
            'origin' => 'steam',
            'steam_present' => true,
            'gog_present' => false,
        ]);
    }

    public function test_unmatched_steam_item_gets_provisional_game_from_appdetails(): void
    {
        $this->steamConnection();
        Http::fake([
            ...$this->baseFakes(),
            'api.steampowered.com/IWishlistService/GetWishlist/*' => Http::response([
                'response' => ['items' => [['appid' => 999777, 'priority' => 0]]],
            ]),
            'store.steampowered.com/api/appdetails*' => Http::response([
                '999777' => ['success' => true, 'data' => ['name' => 'Obscure Indie Gem']],
            ]),
        ]);

        $this->runSync();

        // V11 spirit: no IGDB match still lands a provisional row.
        $this->assertDatabaseHas('games', ['title' => 'Obscure Indie Gem', 'igdb_id' => null]);
        $this->assertDatabaseCount('wishlist_items', 1);
    }

    public function test_gog_wishlist_imported_with_product_id(): void
    {
        $this->gogConnection();
        Http::fake([
            ...$this->baseFakes(),
            'embed.gog.com/user/wishlist.json' => Http::response([
                'wishlist' => ['1207658924' => 1750000000],
            ]),
            'api.igdb.com/v4/external_games' => Http::response([
                ['id' => 9, 'game' => 1942, 'uid' => '1207658924', 'category' => 5],
            ]),
            'api.igdb.com/v4/games' => Http::response([
                ['id' => 1942, 'name' => 'The Witcher 3: Wild Hunt'],
            ]),
        ]);

        $this->runSync();

        $this->assertDatabaseHas('wishlist_items', [
            'origin' => 'gog',
            'gog_present' => true,
            'gog_product_id' => '1207658924',
        ]);
    }

    /**
     * V21: platform wishlist items already owned never import.
     */
    public function test_owned_games_skipped_on_pull(): void
    {
        $connection = $this->gogConnection();
        $game = Game::create(['igdb_id' => 1942, 'title' => 'The Witcher 3: Wild Hunt']);
        OwnedGame::create([
            'user_id' => $this->user->id,
            'platform_connection_id' => $connection->id,
            'game_id' => $game->id,
            'platform_game_id' => '1207658924',
            'playtime_minutes' => null,
            'added_at' => now(),
        ]);
        Http::fake([
            ...$this->baseFakes(),
            'embed.gog.com/user/wishlist.json' => Http::response([
                'wishlist' => ['1207658924' => 1750000000],
            ]),
            'api.igdb.com/v4/external_games' => Http::response([
                ['id' => 9, 'game' => 1942, 'uid' => '1207658924', 'category' => 5],
            ]),
        ]);

        $this->runSync();

        $this->assertDatabaseCount('wishlist_items', 0);
    }

    /**
     * V22: local wishes push to GOG when the external mapping resolves.
     */
    public function test_local_item_pushed_to_gog(): void
    {
        $this->gogConnection();
        $game = Game::create(['igdb_id' => 119388, 'title' => 'Hades II']);
        WishlistItem::create([
            'user_id' => $this->user->id,
            'game_id' => $game->id,
            'added_at' => now(),
        ]);
        Http::fake([
            ...$this->baseFakes(),
            'embed.gog.com/user/wishlist.json' => Http::response(['wishlist' => []]),
            'embed.gog.com/user/wishlist/add/*' => Http::response(['success' => true]),
            'api.igdb.com/v4/external_games' => Http::response([
                ['id' => 11, 'game' => 119388, 'uid' => '2077777777', 'category' => 5],
            ]),
        ]);

        $this->runSync();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'user/wishlist/add/2077777777'));
        $this->assertDatabaseHas('wishlist_items', [
            'game_id' => $game->id,
            'gog_present' => true,
            'gog_product_id' => '2077777777',
        ]);
    }

    /**
     * V22: remote GOG write at most once per state change — re-sync after a
     * successful push sends no second add.
     */
    public function test_push_idempotent_across_syncs(): void
    {
        $this->gogConnection();
        $game = Game::create(['igdb_id' => 119388, 'title' => 'Hades II']);
        WishlistItem::create([
            'user_id' => $this->user->id,
            'game_id' => $game->id,
            'added_at' => now(),
        ]);
        Http::fake([
            ...$this->baseFakes(),
            // First pull: not on GOG yet; second pull reflects our push.
            'embed.gog.com/user/wishlist.json' => Http::sequence()
                ->push(['wishlist' => []])
                ->push(['wishlist' => ['2077777777' => 1750000000]]),
            'embed.gog.com/user/wishlist/add/*' => Http::response(['success' => true]),
            'api.igdb.com/v4/external_games' => Http::response([
                ['id' => 11, 'game' => 119388, 'uid' => '2077777777', 'category' => 5],
            ]),
        ]);

        $this->runSync();
        $this->runSync();

        $adds = Http::recorded(fn ($r) => str_contains($r->url(), 'user/wishlist/add/'));
        $this->assertCount(1, $adds);
        $this->assertDatabaseCount('wishlist_items', 1);
    }

    /**
     * V22: local delete of a GOG-present wish tombstones, pushes the remove,
     * then drops the row.
     */
    public function test_local_delete_pushes_gog_remove(): void
    {
        $this->gogConnection();
        $game = Game::create(['igdb_id' => 1942, 'title' => 'The Witcher 3: Wild Hunt']);
        WishlistItem::create([
            'user_id' => $this->user->id,
            'game_id' => $game->id,
            'added_at' => now(),
            'origin' => 'gog',
            'gog_present' => true,
            'gog_product_id' => '1207658924',
        ]);

        // Local delete → tombstone (row survives, suppressed).
        $this->deleteJson("/api/wishlist/{$game->id}")->assertNoContent();
        $this->assertNotNull(WishlistItem::withoutGlobalScopes()->first()->suppressed_at);
        $this->assertSame([], $this->getJson('/api/wishlist')->json());

        Http::fake([
            ...$this->baseFakes(),
            // GOG still lists it — remote remove pending.
            'embed.gog.com/user/wishlist.json' => Http::response([
                'wishlist' => ['1207658924' => 1750000000],
            ]),
            'embed.gog.com/user/wishlist/remove/*' => Http::response(['success' => true]),
        ]);

        $this->runSync();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'user/wishlist/remove/1207658924'));
        $this->assertDatabaseCount('wishlist_items', 0);
    }

    /**
     * V22: Steam can't be pushed — a suppressed steam wish stays tombstoned
     * so the next pull doesn't resurrect it.
     */
    public function test_suppressed_steam_item_not_reimported(): void
    {
        $this->steamConnection();
        $game = Game::create(['igdb_id' => 119388, 'title' => 'Hades II']);
        WishlistItem::create([
            'user_id' => $this->user->id,
            'game_id' => $game->id,
            'added_at' => now(),
            'origin' => 'steam',
            'steam_present' => true,
        ]);

        $this->deleteJson("/api/wishlist/{$game->id}")->assertNoContent();

        Http::fake([
            ...$this->baseFakes(),
            'api.steampowered.com/IWishlistService/GetWishlist/*' => Http::response([
                'response' => ['items' => [['appid' => 1145360, 'priority' => 0]]],
            ]),
            'api.igdb.com/v4/external_games' => Http::response([
                ['id' => 5, 'game' => 119388, 'uid' => '1145360', 'category' => 1],
            ]),
        ]);

        $this->runSync();
        $this->runSync();

        // Tombstone holds: hidden from the API, no duplicate row.
        $this->assertSame([], $this->getJson('/api/wishlist')->json());
        $this->assertSame(1, WishlistItem::count());
        $this->assertNotNull(WishlistItem::first()->suppressed_at);
    }

    public function test_private_steam_wishlist_skipped_quietly(): void
    {
        $this->steamConnection();
        Http::fake([
            ...$this->baseFakes(),
            'api.steampowered.com/IWishlistService/GetWishlist/*' => Http::response([
                'response' => (object) [],
            ]),
        ]);

        $this->runSync();

        $this->assertDatabaseCount('wishlist_items', 0);
    }

    public function test_sync_endpoint_dispatches_and_throttles(): void
    {
        Queue::fake();

        $this->postJson('/api/wishlist/sync')->assertAccepted();
        Queue::assertPushed(SyncWishlist::class, 1);

        $this->postJson('/api/wishlist/sync')->assertTooManyRequests();
        Queue::assertPushed(SyncWishlist::class, 1);
    }
}
