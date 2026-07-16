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
        // Same list for base and extended fetch — diff = ∅, nothing F2P.
        $this->fakeOwnedGamesSplit($games, $games);
    }

    /**
     * V41: GetOwnedGames is fetched twice per sync — the callable serves the
     * extended list only when include_played_free_games is present.
     */
    private function fakeOwnedGamesSplit(array $base, array $extended): void
    {
        Http::fake([
            'api.steampowered.com/IPlayerService/GetOwnedGames/*' => function (\Illuminate\Http\Client\Request $request) use ($base, $extended) {
                $games = array_key_exists('include_played_free_games', $request->data()) ? $extended : $base;

                return Http::response([
                    'response' => ['game_count' => count($games), 'games' => $games],
                ]);
            },
            // IGDB enrichment runs after ingestion; these tests exercise raw
            // ingestion only, so every match is a miss.
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([]),
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
        // Sequence: first sync sees games (V41: base + extended = 2 calls),
        // second sees a newly-private profile (base short-circuits, 1 call).
        $gamesResponse = [
            'response' => [
                'game_count' => 1,
                'games' => [
                    ['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 1200],
                ],
            ],
        ];
        Http::fake([
            'api.steampowered.com/IPlayerService/GetOwnedGames/*' => Http::sequence()
                ->push($gamesResponse)
                ->push($gamesResponse)
                ->push(['response' => (object) []]),
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([]),
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

    /**
     * V41 (supersedes V23): GetOwnedGames fetched twice — base without the
     * flag, extended with include_played_free_games=1.
     */
    public function test_get_owned_games_double_fetches_with_and_without_free_games_flag(): void
    {
        $this->fakeOwnedGames([
            ['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 1200],
        ]);
        $connection = $this->steamConnection();

        $this->runSync($connection);

        $ownedGamesCalls = Http::recorded(
            fn (\Illuminate\Http\Client\Request $r) => str_contains($r->url(), 'GetOwnedGames'),
        );

        $this->assertCount(2, $ownedGamesCalls);
        [$base, $extended] = $ownedGamesCalls->map(fn (array $pair) => $pair[0]->data())->all();
        $this->assertArrayNotHasKey('include_played_free_games', $base);
        $this->assertEquals(1, $extended['include_played_free_games'] ?? null);
    }

    /**
     * V41: F2P (extended-only appid) with playtime > 0 is ingested and
     * flagged; base (paid) rows stay free_to_play=false.
     */
    public function test_played_f2p_ingested_with_flag(): void
    {
        $portal = ['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 1200];
        $dota = ['appid' => 570, 'name' => 'Dota 2', 'playtime_forever' => 300];
        $this->fakeOwnedGamesSplit([$portal], [$portal, $dota]);
        $connection = $this->steamConnection();

        $this->runSync($connection);

        $this->assertDatabaseCount('owned_games', 2);
        $this->assertDatabaseHas('owned_games', ['platform_game_id' => '570', 'free_to_play' => true]);
        $this->assertDatabaseHas('owned_games', ['platform_game_id' => '620', 'free_to_play' => false]);
    }

    /**
     * V41: zero-playtime F2P is B3 noise — never ingested.
     */
    public function test_zero_playtime_f2p_not_ingested(): void
    {
        $portal = ['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 1200];
        $noise = ['appid' => 1500, 'name' => 'Free Noise Game', 'playtime_forever' => 0];
        $this->fakeOwnedGamesSplit([$portal], [$portal, $noise]);
        $connection = $this->steamConnection();

        $this->runSync($connection);

        $this->assertDatabaseCount('owned_games', 1);
        $this->assertDatabaseMissing('owned_games', ['platform_game_id' => '1500']);
    }

    /**
     * V41: the playtime > 0 guard is F2P-only — paid rows with zero or
     * unknown playtime ingest as before.
     */
    public function test_paid_game_zero_or_null_playtime_still_ingested(): void
    {
        $this->fakeOwnedGames([
            ['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 0],
            ['appid' => 400, 'name' => 'Portal'],
        ]);
        $connection = $this->steamConnection();

        $this->runSync($connection);

        $this->assertDatabaseCount('owned_games', 2);
    }

    /**
     * V41+V24: fresh-set = base ∪ played-F2P — a legacy zero-playtime F2P
     * row (pre-V23 noise) falls out of the fresh-set and gets pruned.
     */
    public function test_legacy_zero_playtime_f2p_row_pruned_on_resync(): void
    {
        $portal = ['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 1200];
        $noise = ['appid' => 1500, 'name' => 'Free Noise Game', 'playtime_forever' => 0];
        $this->fakeOwnedGamesSplit([$portal], [$portal, $noise]);
        $connection = $this->steamConnection();

        // Legacy row for the noise appid, as the pre-V23 bug left behind.
        OwnedGame::create([
            'user_id' => $connection->user_id,
            'platform_connection_id' => $connection->id,
            'game_id' => \App\Models\Game::create(['title' => 'Free Noise Game'])->id,
            'platform_game_id' => '1500',
            'added_at' => now(),
        ]);

        $this->runSync($connection);

        $this->assertDatabaseMissing('owned_games', ['platform_game_id' => '1500']);
        $this->assertDatabaseHas('owned_games', ['platform_game_id' => '620']);
    }

    /**
     * V24: sync prunes rows absent from the fresh response — covers legacy
     * free-game noise (pre-V23) and games actually removed from the account.
     * Snapshot history cascades with the deleted row.
     */
    public function test_resync_removes_games_absent_from_fresh_response(): void
    {
        // V41: 2 GetOwnedGames calls per sync (base + extended).
        $firstSync = [
            'response' => [
                'game_count' => 2,
                'games' => [
                    ['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 1200],
                    ['appid' => 1500, 'name' => 'Free Noise Game', 'playtime_forever' => 0],
                ],
            ],
        ];
        // Second sync: Steam no longer reports the noise appid.
        $secondSync = [
            'response' => [
                'game_count' => 1,
                'games' => [
                    ['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 1250],
                ],
            ],
        ];
        Http::fake([
            'api.steampowered.com/IPlayerService/GetOwnedGames/*' => Http::sequence()
                ->push($firstSync)
                ->push($firstSync)
                ->push($secondSync)
                ->push($secondSync),
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([]),
        ]);
        $connection = $this->steamConnection();
        $this->runSync($connection);

        $this->assertDatabaseCount('owned_games', 2);
        $noiseOwnedGame = OwnedGame::where('platform_game_id', '1500')->first();

        $this->runSync($connection);

        $this->assertDatabaseCount('owned_games', 1);
        $this->assertDatabaseHas('owned_games', ['platform_game_id' => '620']);
        $this->assertDatabaseMissing('owned_games', ['platform_game_id' => '1500']);
        $this->assertDatabaseMissing('playtime_snapshots', ['owned_game_id' => $noiseOwnedGame->id]);
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

    /**
     * T26/V31: deck compat fetched per Steam owned_game every sync.
     */
    public function test_fetches_deck_status_for_steam_games(): void
    {
        Http::fake([
            'api.steampowered.com/IPlayerService/GetOwnedGames/*' => Http::response([
                'response' => ['game_count' => 1, 'games' => [
                    ['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 1200],
                ]],
            ]),
            'store.steampowered.com/saleaction/ajaxgetdeckappcompatibilityreport*' => Http::response([
                'results' => ['resolved_category' => 3],
            ]),
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([]),
        ]);
        $connection = $this->steamConnection();

        $this->runSync($connection);

        $this->assertDatabaseHas('owned_games', [
            'platform_game_id' => '620',
            'deck_status' => 'verified',
        ]);
    }

    /**
     * V31: a transient deck-compat failure never fails the sync, and never
     * clobbers an already-known status with null — only a row that's never
     * once succeeded stays null.
     */
    public function test_deck_status_fetch_failure_does_not_fail_sync_or_clobber_existing(): void
    {
        Http::fake([
            'api.steampowered.com/IPlayerService/GetOwnedGames/*' => Http::response([
                'response' => ['game_count' => 1, 'games' => [
                    ['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 1200],
                ]],
            ]),
            'store.steampowered.com/saleaction/ajaxgetdeckappcompatibilityreport*' => Http::sequence()
                ->push(['results' => ['resolved_category' => 3]])
                ->push(null, 500),
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([]),
        ]);
        $connection = $this->steamConnection();

        $this->runSync($connection);
        $this->assertDatabaseHas('owned_games', ['platform_game_id' => '620', 'deck_status' => 'verified']);

        $this->runSync($connection);

        $this->assertSame('ok', $connection->fresh()->status->value);
        $this->assertDatabaseHas('owned_games', ['platform_game_id' => '620', 'deck_status' => 'verified']);
    }

    /**
     * T67/V63: achievement definitions are fetched per Steam appid and
     * stored keyed per (platform, platform_game_id), not the canonical game.
     */
    public function test_fetches_achievement_definitions_for_steam_games(): void
    {
        Http::fake([
            'api.steampowered.com/IPlayerService/GetOwnedGames/*' => Http::response([
                'response' => ['game_count' => 1, 'games' => [
                    ['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 1200],
                ]],
            ]),
            'api.steampowered.com/ISteamUserStats/GetSchemaForGame/*' => Http::response([
                'game' => ['availableGameStats' => ['achievements' => [
                    ['name' => 'TOWER_OF_ROCKETS', 'displayName' => 'Tower of Rockets', 'description' => 'Build a tower of rockets.', 'icon' => 'https://example.com/icon.jpg'],
                ]]],
            ]),
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([]),
        ]);
        $connection = $this->steamConnection();

        $this->runSync($connection);

        $this->assertDatabaseHas('game_achievement_defs', [
            'platform' => 'steam',
            'platform_game_id' => '620',
            'api_name' => 'TOWER_OF_ROCKETS',
            'name' => 'Tower of Rockets',
            'description' => 'Build a tower of rockets.',
            'icon_url' => 'https://example.com/icon.jpg',
            'points' => null,
        ]);
    }

    /**
     * V66: a transient achievement-schema fetch failure never fails the sync.
     */
    public function test_achievement_def_fetch_failure_does_not_fail_sync(): void
    {
        Http::fake([
            'api.steampowered.com/IPlayerService/GetOwnedGames/*' => Http::response([
                'response' => ['game_count' => 1, 'games' => [
                    ['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 1200],
                ]],
            ]),
            'api.steampowered.com/ISteamUserStats/GetSchemaForGame/*' => Http::response(null, 500),
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([]),
        ]);
        $connection = $this->steamConnection();

        $this->runSync($connection);

        $this->assertSame('ok', $connection->fresh()->status->value);
        $this->assertDatabaseCount('game_achievement_defs', 0);
    }

    /**
     * T67: GetSchemaForGame is cached forever client-side - a second sync for
     * the same appid does not re-fetch (mirrors V4's cache-once class).
     */
    public function test_achievement_schema_not_refetched_on_second_sync(): void
    {
        Http::fake([
            'api.steampowered.com/IPlayerService/GetOwnedGames/*' => Http::response([
                'response' => ['game_count' => 1, 'games' => [
                    ['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 1200],
                ]],
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
        $connection = $this->steamConnection();

        $this->runSync($connection);
        $this->runSync($connection);

        $this->assertCount(
            1,
            Http::recorded(fn ($request) => str_contains($request->url(), 'GetSchemaForGame')),
        );
    }
}
