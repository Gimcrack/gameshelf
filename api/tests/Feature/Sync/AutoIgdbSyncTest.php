<?php

namespace Tests\Feature\Sync;

use App\Jobs\MatchConnectionIgdb;
use App\Jobs\RefreshGameIgdb;
use App\Jobs\SyncConnection;
use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use App\Services\Library\GameFromIgdb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * T51/V50: platform sync auto-queues IGDB work, fanned out per V39 — provisional
 * games match via their connection, matched games gone IGDB-stale (>24h) get a
 * per-game refresh, fresh (<24h) games are skipped. Every IGDB canonical write
 * stamps games.igdb_synced_at so the freshness gate can see it.
 */
class AutoIgdbSyncTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSteam(array $games): void
    {
        Http::fake([
            'api.steampowered.com/IPlayerService/GetOwnedGames/*' => Http::response([
                'response' => ['game_count' => count($games), 'games' => $games],
            ]),
            'id.twitch.tv/oauth2/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
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

    private function matchedOwnedGame(PlatformConnection $connection, string $appId, ?\DateTimeInterface $syncedAt): Game
    {
        $game = Game::create([
            'igdb_id' => (int) $appId + 1000,
            'title' => "Game {$appId}",
            'igdb_synced_at' => $syncedAt,
        ]);
        OwnedGame::create([
            'user_id' => $connection->user_id,
            'platform_connection_id' => $connection->id,
            'game_id' => $game->id,
            'platform_game_id' => $appId,
            'playtime_minutes' => 100,
            'added_at' => now(),
        ]);

        return $game;
    }

    public function test_provisional_owned_games_dispatch_match_job(): void
    {
        Queue::fake();
        $this->fakeSteam([['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 10]]);
        $connection = $this->steamConnection();

        (new SyncConnection($connection->id))->handle();

        Queue::assertPushed(MatchConnectionIgdb::class, fn ($job) => $job->connectionId === $connection->id);
    }

    public function test_stale_matched_game_dispatches_refresh(): void
    {
        Queue::fake();
        $connection = $this->steamConnection();
        $stale = $this->matchedOwnedGame($connection, '620', now()->subDays(2));
        $this->fakeSteam([['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 10]]);

        (new SyncConnection($connection->id))->handle();

        Queue::assertPushed(RefreshGameIgdb::class, fn ($job) => $job->gameId === $stale->id);
    }

    public function test_never_synced_matched_game_dispatches_refresh(): void
    {
        Queue::fake();
        $connection = $this->steamConnection();
        $never = $this->matchedOwnedGame($connection, '620', null);
        $this->fakeSteam([['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 10]]);

        (new SyncConnection($connection->id))->handle();

        Queue::assertPushed(RefreshGameIgdb::class, fn ($job) => $job->gameId === $never->id);
    }

    public function test_fresh_matched_game_skips_refresh(): void
    {
        Queue::fake();
        $connection = $this->steamConnection();
        $this->matchedOwnedGame($connection, '620', now()->subHours(2));
        $this->fakeSteam([['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 10]]);

        (new SyncConnection($connection->id))->handle();

        Queue::assertNotPushed(RefreshGameIgdb::class);
    }

    public function test_game_from_igdb_create_stamps_synced_at(): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'api.igdb.com/v4/games' => Http::response([['id' => 900, 'name' => 'Created Game']]),
            'api.igdb.com/v4/game_time_to_beats' => Http::response([]),
        ]);

        $game = app(GameFromIgdb::class)->findOrCreate(900);

        $this->assertNotNull($game->igdb_synced_at);
    }
}
