<?php

namespace Tests\Feature\Sync;

use App\Jobs\SyncConnection;
use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * V58: SteamFamilySyncer ingestion via SyncConnection's steam_family branch.
 */
class SteamFamilySyncJobTest extends TestCase
{
    use RefreshDatabase;

    private const MEMBER_STEAM_ID = '76561197960287999';

    private function fakeOwnedGames(array $games): void
    {
        Http::fake([
            'api.steampowered.com/IPlayerService/GetOwnedGames/*' => Http::response([
                'response' => ['game_count' => count($games), 'games' => $games],
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function familyConnection(array $overrides = []): PlatformConnection
    {
        return PlatformConnection::factory()->create([
            'platform' => 'steam_family',
            'external_account_id' => self::MEMBER_STEAM_ID,
            'status' => 'pending',
            ...$overrides,
        ]);
    }

    private function runSync(PlatformConnection $connection): void
    {
        (new SyncConnection($connection->id))->handle();
    }

    public function test_category_62_and_not_owned_game_surfaces_as_shared(): void
    {
        $this->fakeOwnedGames([
            ['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 5000],
        ]);
        $connection = $this->familyConnection();
        Http::fake([
            'store.steampowered.com/api/appdetails*' => Http::response([
                '620' => ['success' => true, 'data' => ['categories' => [['id' => 62]]]],
            ]),
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([]),
        ]);

        $this->runSync($connection);

        $this->assertDatabaseHas('owned_games', [
            'platform_connection_id' => $connection->id,
            'platform_game_id' => '620',
            'shared' => 1,
            'playtime_minutes' => null,
        ]);
        $this->assertSame('ok', $connection->fresh()->status->value);
    }

    public function test_own_owned_game_excludes_shared_duplicate_even_if_also_in_member_list(): void
    {
        $connection = $this->familyConnection();
        $game = Game::create(['title' => 'Portal 2']);
        $ownConnection = PlatformConnection::factory()->create([
            'user_id' => $connection->user_id,
            'platform' => 'steam',
        ]);
        OwnedGame::create([
            'user_id' => $connection->user_id,
            'platform_connection_id' => $ownConnection->id,
            'game_id' => $game->id,
            'platform_game_id' => '620',
            'added_at' => now(),
        ]);

        $this->fakeOwnedGames([
            ['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 5000],
        ]);
        Http::fake([
            'store.steampowered.com/api/appdetails*' => Http::response([
                '620' => ['success' => true, 'data' => ['categories' => [['id' => 62]]]],
            ]),
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([]),
        ]);

        $this->runSync($connection);

        // Caller's own ownership wins — no shared row created for this connection.
        $this->assertDatabaseMissing('owned_games', [
            'platform_connection_id' => $connection->id,
            'platform_game_id' => '620',
        ]);
        $this->assertDatabaseCount('owned_games', 1);
    }

    public function test_private_member_profile_sets_error_state_without_wiping_shared_rows(): void
    {
        $gamesResponse = [
            'response' => [
                'game_count' => 1,
                'games' => [
                    ['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 5000],
                ],
            ],
        ];
        Http::fake([
            // V41: successful sync makes 2 GetOwnedGames calls (base +
            // extended); the private-profile sync short-circuits after 1.
            'api.steampowered.com/IPlayerService/GetOwnedGames/*' => Http::sequence()
                ->push($gamesResponse)
                ->push($gamesResponse)
                ->push(['response' => (object) []]),
            'store.steampowered.com/api/appdetails*' => Http::response([
                '620' => ['success' => true, 'data' => ['categories' => [['id' => 62]]]],
            ]),
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([]),
        ]);
        $connection = $this->familyConnection();

        $this->runSync($connection);
        $this->assertDatabaseCount('owned_games', 1);

        $this->runSync($connection);

        $this->assertSame('error_private', $connection->fresh()->status->value);
        // V15-style short-circuit: reconciliation never ran, shared row kept.
        $this->assertDatabaseCount('owned_games', 1);
        $this->assertDatabaseHas('owned_games', ['platform_game_id' => '620']);
    }

    /**
     * T67/V65: shared-appid achievement defs are fetched too - T68's unlock
     * sync (caller's own steamid) needs them, and shared games are excluded
     * from SteamSyncer's own ingestion, so this is the only path that would.
     */
    public function test_fetches_achievement_definitions_for_shared_games(): void
    {
        $this->fakeOwnedGames([
            ['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 5000],
        ]);
        $connection = $this->familyConnection();
        Http::fake([
            'store.steampowered.com/api/appdetails*' => Http::response([
                '620' => ['success' => true, 'data' => ['categories' => [['id' => 62]]]],
            ]),
            'api.steampowered.com/ISteamUserStats/GetSchemaForGame/*' => Http::response([
                'game' => ['availableGameStats' => ['achievements' => [
                    ['name' => 'TOWER_OF_ROCKETS', 'displayName' => 'Tower of Rockets', 'description' => null, 'icon' => null],
                ]]],
            ]),
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([]),
        ]);

        $this->runSync($connection);

        $this->assertDatabaseHas('game_achievement_defs', [
            'platform' => 'steam',
            'platform_game_id' => '620',
            'api_name' => 'TOWER_OF_ROCKETS',
        ]);
    }

    /**
     * T68/V65: shared-row achievement unlock uses the CALLER's own steamid
     * (their direct steam connection), never the family member's.
     */
    public function test_shared_game_achievement_sync_uses_callers_own_steamid(): void
    {
        $connection = $this->familyConnection();
        $callerSteamId = '76561197960211111';
        PlatformConnection::factory()->create([
            'user_id' => $connection->user_id,
            'platform' => 'steam',
            'external_account_id' => $callerSteamId,
        ]);

        $this->fakeOwnedGames([
            ['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 5000],
        ]);
        Http::fake([
            'store.steampowered.com/api/appdetails*' => Http::response([
                '620' => ['success' => true, 'data' => ['categories' => [['id' => 62]]]],
            ]),
            'api.steampowered.com/ISteamUserStats/GetSchemaForGame/*' => Http::response([
                'game' => ['availableGameStats' => ['achievements' => [
                    ['name' => 'TOWER_OF_ROCKETS', 'displayName' => 'Tower of Rockets', 'description' => null, 'icon' => null],
                ]]],
            ]),
            'api.steampowered.com/ISteamUserStats/GetPlayerAchievements/*' => Http::response([
                'playerstats' => [
                    'success' => true,
                    'achievements' => [
                        ['apiname' => 'TOWER_OF_ROCKETS', 'achieved' => 1, 'unlocktime' => 1750000000],
                    ],
                ],
            ]),
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([]),
        ]);

        $this->runSync($connection);

        $ownedGame = OwnedGame::where('platform_game_id', '620')->firstOrFail();
        $this->assertDatabaseHas('owned_game_achievements', [
            'owned_game_id' => $ownedGame->id,
            'unlocked' => 1,
        ]);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'GetPlayerAchievements')
            && $request['steamid'] === $callerSteamId);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'GetPlayerAchievements')
            && $request['steamid'] === self::MEMBER_STEAM_ID);
    }
}
