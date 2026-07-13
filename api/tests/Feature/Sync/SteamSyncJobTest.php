<?php

namespace Tests\Feature\Sync;

use App\Jobs\SyncConnection;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use App\Models\PlaytimeSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SteamSyncJobTest extends TestCase
{
    use RefreshDatabase;

    private function fakeOwnedGames(array $games): void
    {
        Http::fake([
            'api.steampowered.com/IPlayerService/GetOwnedGames/*' => Http::response([
                'response' => [
                    'game_count' => count($games),
                    'games' => $games,
                ],
            ]),
        ]);
    }

    private function steamConnection(): PlatformConnection
    {
        return PlatformConnection::factory()->create([
            'platform' => 'steam',
            'external_account_id' => '76561197960287930',
            'status' => 'pending',
        ]);
    }

    private function runSync(PlatformConnection $connection): void
    {
        (new SyncConnection($connection->id))->handle();
    }

    public function test_ingests_owned_games_with_provisional_game_rows(): void
    {
        $this->fakeOwnedGames([
            ['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 1200, 'rtime_last_played' => 1750000000],
            ['appid' => 570, 'name' => 'Dota 2', 'playtime_forever' => 0],
        ]);
        $connection = $this->steamConnection();

        $this->runSync($connection);

        $this->assertDatabaseCount('owned_games', 2);
        $this->assertDatabaseHas('games', ['title' => 'Portal 2', 'igdb_id' => null]);
        $this->assertDatabaseHas('owned_games', [
            'platform_game_id' => '620',
            'playtime_minutes' => 1200,
        ]);
        $this->assertSame('ok', $connection->fresh()->status->value);
        $this->assertNotNull($connection->fresh()->last_synced_at);
    }

    /**
     * V10: re-sync upserts — never duplicates owned_games rows.
     */
    public function test_resync_does_not_duplicate_owned_games(): void
    {
        $this->fakeOwnedGames([
            ['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 1200],
        ]);
        $connection = $this->steamConnection();

        $this->runSync($connection);
        $this->runSync($connection);

        $this->assertDatabaseCount('owned_games', 1);
        $this->assertDatabaseCount('games', 1);
    }

    /**
     * V16: every sync appends a playtime snapshot per owned game with data.
     */
    public function test_each_sync_appends_playtime_snapshots(): void
    {
        $this->fakeOwnedGames([
            ['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 1200],
            ['appid' => 570, 'name' => 'Dota 2', 'playtime_forever' => 30],
        ]);
        $connection = $this->steamConnection();

        $this->runSync($connection);
        $this->runSync($connection);

        $this->assertDatabaseCount('playtime_snapshots', 4);
        $ownedGame = OwnedGame::where('platform_game_id', '620')->first();
        $this->assertSame(2, PlaytimeSnapshot::where('owned_game_id', $ownedGame->id)->count());
    }

    /**
     * V15: private profile becomes a distinct error state — never a silent
     * zero-game sync.
     */
    public function test_private_profile_sets_error_state_and_keeps_games(): void
    {
        // Steam returns an empty response object for private profiles.
        // Sequence: first sync sees games, second sees a newly-private profile.
        Http::fake([
            'api.steampowered.com/IPlayerService/GetOwnedGames/*' => Http::sequence()
                ->push([
                    'response' => [
                        'game_count' => 1,
                        'games' => [
                            ['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 1200],
                        ],
                    ],
                ])
                ->push(['response' => (object) []]),
        ]);
        $connection = $this->steamConnection();

        $this->runSync($connection);
        $this->runSync($connection);

        $this->assertSame('error_private', $connection->fresh()->status->value);
        $this->assertDatabaseCount('owned_games', 1);
    }

    public function test_api_failure_sets_error_state(): void
    {
        Http::fake([
            'api.steampowered.com/IPlayerService/GetOwnedGames/*' => Http::response(null, 500),
        ]);
        $connection = $this->steamConnection();

        try {
            $this->runSync($connection);
        } catch (\Throwable) {
            // Job rethrows for queue retry semantics.
        }

        $this->assertSame('error', $connection->fresh()->status->value);
    }

    public function test_playtime_null_when_steam_omits_it(): void
    {
        $this->fakeOwnedGames([
            ['appid' => 620, 'name' => 'Portal 2'],
        ]);
        $connection = $this->steamConnection();

        $this->runSync($connection);

        $this->assertDatabaseHas('owned_games', [
            'platform_game_id' => '620',
            'playtime_minutes' => null,
        ]);
        // V16 only snapshots games that actually have playtime data.
        $this->assertDatabaseCount('playtime_snapshots', 0);
    }
}
