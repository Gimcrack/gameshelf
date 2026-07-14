<?php

namespace Tests\Feature\Sync;

use App\Jobs\SyncConnection;
use App\Models\Game;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GogSyncJobTest extends TestCase
{
    use RefreshDatabase;

    private function fakeIgdbMiss(): array
    {
        return [
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([]),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $products
     */
    private function fakeOwnedProducts(array $products): void
    {
        Http::fake([
            'embed.gog.com/account/getFilteredProducts*' => Http::response([
                'totalPages' => 1,
                'products' => $products,
            ]),
            ...$this->fakeIgdbMiss(),
        ]);
    }

    private function gogConnection(array $overrides = []): PlatformConnection
    {
        return PlatformConnection::factory()->create([
            'platform' => 'gog',
            'external_account_id' => '48628349957132247',
            'status' => 'pending',
            'auth_token' => 'gog-access-token',
            'refresh_token' => 'gog-refresh-token',
            'token_expires_at' => now()->addHour(),
            ...$overrides,
        ]);
    }

    private function runSync(PlatformConnection $connection): void
    {
        (new SyncConnection($connection->id))->handle();
    }

    public function test_ingests_gog_products_with_null_playtime(): void
    {
        $this->fakeOwnedProducts([
            ['id' => 1207658924, 'title' => 'The Witcher 3: Wild Hunt'],
            ['id' => 1207666883, 'title' => 'Disco Elysium'],
        ]);
        $connection = $this->gogConnection();

        $this->runSync($connection);

        $this->assertDatabaseCount('owned_games', 2);
        // §C: GOG playtime unavailable — null means unknown, never 0 (V12).
        $this->assertDatabaseHas('owned_games', [
            'platform_game_id' => '1207658924',
            'playtime_minutes' => null,
        ]);
        $this->assertDatabaseCount('playtime_snapshots', 0);
        $this->assertSame('ok', $connection->fresh()->status->value);
    }

    /**
     * V10: re-sync upserts, never duplicates.
     */
    public function test_resync_does_not_duplicate_owned_games(): void
    {
        $this->fakeOwnedProducts([
            ['id' => 1207658924, 'title' => 'The Witcher 3: Wild Hunt'],
        ]);
        $connection = $this->gogConnection();

        $this->runSync($connection);
        $this->runSync($connection);

        $this->assertDatabaseCount('owned_games', 1);
        $this->assertDatabaseCount('games', 1);
    }

    /**
     * V14: expired token is refreshed before the library call, and the new
     * tokens are persisted.
     */
    public function test_expired_token_refreshed_before_sync(): void
    {
        Http::fake([
            'auth.gog.com/token*' => Http::response([
                'access_token' => 'fresh-access-token',
                'refresh_token' => 'fresh-refresh-token',
                'expires_in' => 3600,
                'user_id' => '48628349957132247',
            ]),
            'embed.gog.com/account/getFilteredProducts*' => Http::response([
                'totalPages' => 1,
                'products' => [['id' => 1207658924, 'title' => 'The Witcher 3: Wild Hunt']],
            ]),
            ...$this->fakeIgdbMiss(),
        ]);
        $connection = $this->gogConnection(['token_expires_at' => now()->subMinute()]);

        $this->runSync($connection);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'auth.gog.com/token')
                && $request['grant_type'] === 'refresh_token'
                && $request['refresh_token'] === 'gog-refresh-token';
        });
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'embed.gog.com')
                && $request->header('Authorization')[0] === 'Bearer fresh-access-token';
        });

        $fresh = $connection->fresh();
        $this->assertSame('fresh-access-token', $fresh->auth_token);
        $this->assertSame('fresh-refresh-token', $fresh->refresh_token);
        $this->assertSame('ok', $fresh->status->value);
    }

    public function test_refresh_failure_sets_error_state(): void
    {
        Http::fake([
            'auth.gog.com/token*' => Http::response(['error' => 'invalid_grant'], 400),
        ]);
        $connection = $this->gogConnection(['token_expires_at' => now()->subMinute()]);

        try {
            $this->runSync($connection);
        } catch (\Throwable) {
            // Job rethrows for queue retry semantics.
        }

        $this->assertSame('error', $connection->fresh()->status->value);
    }

    public function test_gog_api_failure_sets_error_state(): void
    {
        Http::fake([
            'embed.gog.com/account/getFilteredProducts*' => Http::response(null, 500),
        ]);
        $connection = $this->gogConnection();

        try {
            $this->runSync($connection);
        } catch (\Throwable) {
            // Job rethrows for queue retry semantics.
        }

        $this->assertSame('error', $connection->fresh()->status->value);
    }

    public function test_paginated_product_lists_are_fully_ingested(): void
    {
        Http::fake([
            'embed.gog.com/account/getFilteredProducts*page=2*' => Http::response([
                'totalPages' => 2,
                'products' => [['id' => 2, 'title' => 'Page Two Game']],
            ]),
            'embed.gog.com/account/getFilteredProducts*' => Http::response([
                'totalPages' => 2,
                'products' => [['id' => 1, 'title' => 'Page One Game']],
            ]),
            ...$this->fakeIgdbMiss(),
        ]);
        $connection = $this->gogConnection();

        $this->runSync($connection);

        $this->assertDatabaseCount('owned_games', 2);
    }

    /**
     * V1: same real-world game owned on steam and gog keeps one owned_games
     * row per platform while IGDB matching collapses both onto one canonical
     * games row (V7).
     */
    public function test_multi_platform_ownership_dedupes_to_one_canonical_game(): void
    {
        $user = User::factory()->create();
        $igdbMatch = Http::response([
            [
                'id' => 1942,
                'name' => 'The Witcher 3: Wild Hunt',
                'cover' => ['url' => '//images.igdb.com/witcher3.jpg'],
                'genres' => [['name' => 'RPG']],
            ],
        ]);

        Http::fake([
            'api.steampowered.com/IPlayerService/GetOwnedGames/*' => Http::response([
                'response' => [
                    'game_count' => 1,
                    'games' => [['appid' => 292030, 'name' => 'The Witcher 3: Wild Hunt', 'playtime_forever' => 900]],
                ],
            ]),
            'embed.gog.com/account/getFilteredProducts*' => Http::response([
                'totalPages' => 1,
                'products' => [['id' => 1207658924, 'title' => 'The Witcher 3: Wild Hunt']],
            ]),
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => $igdbMatch,
        ]);

        $steam = PlatformConnection::factory()->create([
            'user_id' => $user->id,
            'platform' => 'steam',
            'external_account_id' => '76561197960287930',
            'status' => 'pending',
        ]);
        $gog = $this->gogConnection(['user_id' => $user->id]);

        $this->runSync($steam);
        $this->runSync($gog);

        // V1: one owned_games row per platform — never collapsed.
        $this->assertDatabaseCount('owned_games', 2);
        // V7: one canonical games row via igdb_id.
        $this->assertSame(1, Game::count());
        $this->assertDatabaseHas('games', ['igdb_id' => 1942]);
    }
}
