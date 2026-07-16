<?php

namespace Tests\Feature\Sync;

use App\Jobs\SyncConnection;
use App\Models\Game;
use App\Models\PlatformConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class XboxSyncJobTest extends TestCase
{
    use RefreshDatabase;

    private const XUID = '2669321029139235';

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

    private function fakeXstsChain(): array
    {
        return [
            'user.auth.xboxlive.com/user/authenticate*' => Http::response([
                'Token' => 'xbl-user-token',
                'DisplayClaims' => ['xui' => [['uhs' => 'user-hash-123']]],
            ]),
            'xsts.auth.xboxlive.com/xsts/authorize*' => Http::response([
                'Token' => 'xsts-token',
                'DisplayClaims' => ['xui' => [['uhs' => 'user-hash-123', 'xid' => self::XUID]]],
            ]),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $titles
     */
    private function fakeTitleHistory(array $titles): void
    {
        Http::fake([
            'titlehub.xboxlive.com/*' => Http::response(['xuid' => self::XUID, 'titles' => $titles]),
            ...$this->fakeXstsChain(),
            ...$this->fakeIgdbMiss(),
        ]);
    }

    private function xboxConnection(array $overrides = []): PlatformConnection
    {
        return PlatformConnection::factory()->create([
            'platform' => 'xbox',
            'external_account_id' => self::XUID,
            'status' => 'pending',
            'auth_token' => 'ms-access-token',
            'refresh_token' => 'ms-refresh-token',
            'token_expires_at' => now()->addHour(),
            ...$overrides,
        ]);
    }

    private function runSync(PlatformConnection $connection): void
    {
        (new SyncConnection($connection->id))->handle();
    }

    public function test_ingests_xbox_titles_with_null_playtime(): void
    {
        $this->fakeTitleHistory([
            ['titleId' => '1153748408', 'name' => 'Forza Horizon 5', 'type' => 'Game'],
            ['titleId' => '1332356090', 'name' => 'Halo Infinite', 'type' => 'Game'],
        ]);
        $connection = $this->xboxConnection();

        $this->runSync($connection);

        $this->assertDatabaseCount('owned_games', 2);
        // §C: titlehub has no playtime figure — null means unknown, never 0 (V12).
        $this->assertDatabaseHas('owned_games', [
            'platform_game_id' => '1153748408',
            'playtime_minutes' => null,
        ]);
        $this->assertDatabaseCount('playtime_snapshots', 0);
        $this->assertSame('ok', $connection->fresh()->status->value);
    }

    /**
     * §C caveat: titlehub also returns non-game apps — only Game entries
     * belong in the library.
     */
    public function test_non_game_titles_excluded(): void
    {
        $this->fakeTitleHistory([
            ['titleId' => '1153748408', 'name' => 'Forza Horizon 5', 'type' => 'Game'],
            ['titleId' => '955567532', 'name' => 'Netflix', 'type' => 'App'],
        ]);
        $connection = $this->xboxConnection();

        $this->runSync($connection);

        $this->assertDatabaseCount('owned_games', 1);
        $this->assertDatabaseMissing('owned_games', ['platform_game_id' => '955567532']);
    }

    /**
     * V10: re-sync upserts, never duplicates.
     */
    public function test_resync_does_not_duplicate_owned_games(): void
    {
        $this->fakeTitleHistory([
            ['titleId' => '1153748408', 'name' => 'Forza Horizon 5', 'type' => 'Game'],
        ]);
        $connection = $this->xboxConnection();

        $this->runSync($connection);
        $this->runSync($connection);

        $this->assertDatabaseCount('owned_games', 1);
        $this->assertDatabaseCount('games', 1);
    }

    /**
     * V24-style: sync reflects the fresh title-history response — a title
     * absent from a later sync is pruned.
     */
    public function test_resync_removes_titles_absent_from_fresh_response(): void
    {
        Http::fake([
            'titlehub.xboxlive.com/*' => Http::sequence()
                ->push(['xuid' => self::XUID, 'titles' => [
                    ['titleId' => '1153748408', 'name' => 'Forza Horizon 5', 'type' => 'Game'],
                    ['titleId' => '1332356090', 'name' => 'Halo Infinite', 'type' => 'Game'],
                ]])
                ->push(['xuid' => self::XUID, 'titles' => [
                    ['titleId' => '1153748408', 'name' => 'Forza Horizon 5', 'type' => 'Game'],
                ]]),
            ...$this->fakeXstsChain(),
            ...$this->fakeIgdbMiss(),
        ]);
        $connection = $this->xboxConnection();
        $this->runSync($connection);
        $this->assertDatabaseCount('owned_games', 2);

        $this->runSync($connection);

        $this->assertDatabaseCount('owned_games', 1);
        $this->assertDatabaseHas('owned_games', ['platform_game_id' => '1153748408']);
        $this->assertDatabaseMissing('owned_games', ['platform_game_id' => '1332356090']);
    }

    /**
     * V14: expired MS token is refreshed before the XBL/XSTS chain runs, and
     * the new tokens are persisted.
     */
    public function test_expired_token_refreshed_before_sync(): void
    {
        Http::fake([
            'login.microsoftonline.com/consumers/oauth2/v2.0/token*' => Http::response([
                'access_token' => 'fresh-access-token',
                'refresh_token' => 'fresh-refresh-token',
                'expires_in' => 3600,
            ]),
            'titlehub.xboxlive.com/*' => Http::response(['xuid' => self::XUID, 'titles' => [
                ['titleId' => '1153748408', 'name' => 'Forza Horizon 5', 'type' => 'Game'],
            ]]),
            ...$this->fakeXstsChain(),
            ...$this->fakeIgdbMiss(),
        ]);
        $connection = $this->xboxConnection(['token_expires_at' => now()->subMinute()]);

        $this->runSync($connection);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'login.microsoftonline.com')
                && $request['grant_type'] === 'refresh_token'
                && $request['refresh_token'] === 'ms-refresh-token';
        });
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'user.auth.xboxlive.com')
                && $request['Properties']['RpsTicket'] === 'd=fresh-access-token';
        });

        $fresh = $connection->fresh();
        $this->assertSame('fresh-access-token', $fresh->auth_token);
        $this->assertSame('fresh-refresh-token', $fresh->refresh_token);
        $this->assertSame('ok', $fresh->status->value);
    }

    public function test_refresh_failure_sets_error_state(): void
    {
        Http::fake([
            'login.microsoftonline.com/consumers/oauth2/v2.0/token*' => Http::response(['error' => 'invalid_grant'], 400),
        ]);
        $connection = $this->xboxConnection(['token_expires_at' => now()->subMinute()]);

        try {
            $this->runSync($connection);
        } catch (\Throwable) {
            // Job rethrows for queue retry semantics.
        }

        $this->assertSame('error', $connection->fresh()->status->value);
    }

    public function test_titlehub_api_failure_sets_error_state(): void
    {
        Http::fake([
            'titlehub.xboxlive.com/*' => Http::response(null, 500),
            ...$this->fakeXstsChain(),
        ]);
        $connection = $this->xboxConnection();

        try {
            $this->runSync($connection);
        } catch (\Throwable) {
            // Job rethrows for queue retry semantics.
        }

        $this->assertSame('error', $connection->fresh()->status->value);
    }

    /**
     * V1/V7: same real-world game owned on steam and xbox keeps one
     * owned_games row per platform while IGDB matching collapses both onto
     * one canonical games row.
     */
    public function test_multi_platform_ownership_dedupes_to_one_canonical_game(): void
    {
        $user = \App\Models\User::factory()->create();
        $igdbMatch = Http::response([
            [
                'id' => 358827,
                'name' => 'Forza Horizon 5',
                'cover' => ['url' => '//images.igdb.com/forza5.jpg'],
                'genres' => [['name' => 'Racing']],
            ],
        ]);

        Http::fake([
            'api.steampowered.com/IPlayerService/GetOwnedGames/*' => Http::response([
                'response' => [
                    'game_count' => 1,
                    'games' => [['appid' => 1551360, 'name' => 'Forza Horizon 5', 'playtime_forever' => 900]],
                ],
            ]),
            'titlehub.xboxlive.com/*' => Http::response(['xuid' => self::XUID, 'titles' => [
                ['titleId' => '1153748408', 'name' => 'Forza Horizon 5', 'type' => 'Game'],
            ]]),
            ...$this->fakeXstsChain(),
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => $igdbMatch,
            'api.igdb.com/v4/game_time_to_beats' => Http::response([]),
        ]);

        $steam = PlatformConnection::factory()->create([
            'user_id' => $user->id,
            'platform' => 'steam',
            'external_account_id' => '76561197960287930',
            'status' => 'pending',
        ]);
        $xbox = $this->xboxConnection(['user_id' => $user->id]);

        $this->runSync($steam);
        $this->runSync($xbox);

        // V1: one owned_games row per platform — never collapsed.
        $this->assertDatabaseCount('owned_games', 2);
        // V7: one canonical games row via igdb_id.
        $this->assertSame(1, Game::count());
        $this->assertDatabaseHas('games', ['igdb_id' => 358827]);
    }

    /**
     * T69/V63: 1 achievements.xboxlive.com call yields both the def
     * (game_achievement_defs) and the unlock state (owned_game_achievements),
     * unlike Steam's 2-call split.
     */
    public function test_syncs_xbox_achievements_def_and_unlock_state_in_one_call(): void
    {
        Http::fake([
            'titlehub.xboxlive.com/*' => Http::response(['xuid' => self::XUID, 'titles' => [
                ['titleId' => '1153748408', 'name' => 'Forza Horizon 5', 'type' => 'Game'],
            ]]),
            'achievements.xboxlive.com/*' => Http::response(['achievements' => [
                [
                    'id' => 1,
                    'name' => 'Speed Demon',
                    'description' => 'Reach top speed.',
                    'progressState' => 'Achieved',
                    'progression' => ['timeUnlocked' => '2026-01-05T16:33:36.6030000Z'],
                    'rewards' => [['type' => 'Gamerscore', 'value' => '20']],
                    'mediaAssets' => [['type' => 'Icon', 'url' => 'https://example.com/icon.jpg']],
                ],
                [
                    'id' => 2,
                    'name' => 'Collector',
                    'description' => 'Collect everything.',
                    'progressState' => 'NotStarted',
                    'progression' => ['timeUnlocked' => '9999-12-31T00:00:00Z'],
                    'rewards' => [['type' => 'Gamerscore', 'value' => '50']],
                    'mediaAssets' => [],
                ],
            ]]),
            ...$this->fakeXstsChain(),
            ...$this->fakeIgdbMiss(),
        ]);
        $connection = $this->xboxConnection();

        $this->runSync($connection);

        $this->assertDatabaseHas('game_achievement_defs', [
            'platform' => 'xbox',
            'platform_game_id' => '1153748408',
            'api_name' => '1',
            'name' => 'Speed Demon',
            'points' => 20,
        ]);
        $ownedGame = \App\Models\OwnedGame::where('platform_game_id', '1153748408')->firstOrFail();
        $this->assertDatabaseHas('owned_game_achievements', [
            'owned_game_id' => $ownedGame->id,
            'unlocked' => 1,
        ]);
        // Locked achievement's placeholder timeUnlocked is never trusted.
        $this->assertDatabaseHas('owned_game_achievements', [
            'owned_game_id' => $ownedGame->id,
            'unlocked' => 0,
            'unlocked_at' => null,
        ]);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'achievements.xboxlive.com')
            && $request->hasHeader('x-xbl-contract-version', '2')
            && $request['titleId'] === '1153748408');
    }

    /**
     * V66: a transient achievements-endpoint failure never fails the sync.
     */
    public function test_xbox_achievement_fetch_failure_does_not_fail_sync(): void
    {
        Http::fake([
            'titlehub.xboxlive.com/*' => Http::response(['xuid' => self::XUID, 'titles' => [
                ['titleId' => '1153748408', 'name' => 'Forza Horizon 5', 'type' => 'Game'],
            ]]),
            'achievements.xboxlive.com/*' => Http::response(null, 500),
            ...$this->fakeXstsChain(),
            ...$this->fakeIgdbMiss(),
        ]);
        $connection = $this->xboxConnection();

        $this->runSync($connection);

        $this->assertSame('ok', $connection->fresh()->status->value);
        $this->assertDatabaseCount('owned_game_achievements', 0);
    }
}
