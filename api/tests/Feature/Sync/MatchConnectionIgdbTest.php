<?php

namespace Tests\Feature\Sync;

use App\Jobs\MatchConnectionIgdb;
use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MatchConnectionIgdbTest extends TestCase
{
    use RefreshDatabase;

    private function own(PlatformConnection $connection, Game $game, string $platformGameId): OwnedGame
    {
        return OwnedGame::create([
            'user_id' => $connection->user_id,
            'platform_connection_id' => $connection->id,
            'game_id' => $game->id,
            'platform_game_id' => $platformGameId,
            'added_at' => now(),
        ]);
    }

    /**
     * T32/V39: child job matches one connection's provisional games.
     */
    public function test_matches_provisional_games_for_the_connection(): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([
                ['id' => 72, 'name' => 'Portal 2', 'genres' => []],
            ]),
            'api.igdb.com/v4/game_time_to_beats' => Http::response([]),
        ]);
        $user = User::factory()->create();
        $connection = PlatformConnection::factory()->create(['user_id' => $user->id, 'status' => 'ok']);

        $provisional = Game::create(['title' => 'Portal 2']);
        $this->own($connection, $provisional, '620');

        (new MatchConnectionIgdb($connection->id))->handle(app(\App\Services\Igdb\GameMatcher::class));

        $this->assertSame(72, $provisional->fresh()->igdb_id);
    }

    /**
     * V26 (via GameMatcher): one game's IGDB failure never aborts the rest
     * of the connection's batch.
     */
    public function test_one_game_failure_does_not_abort_the_rest(): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::sequence()
                ->push(null, 500)
                ->push([['id' => 72, 'name' => 'Portal 2', 'genres' => []]]),
            'api.igdb.com/v4/game_time_to_beats' => Http::response([]),
        ]);
        $user = User::factory()->create();
        $connection = PlatformConnection::factory()->create(['user_id' => $user->id, 'status' => 'ok']);

        $fails = Game::create(['title' => 'Fails To Match']);
        $this->own($connection, $fails, '111');

        $matches = Game::create(['title' => 'Portal 2']);
        $this->own($connection, $matches, '620');

        (new MatchConnectionIgdb($connection->id))->handle(app(\App\Services\Igdb\GameMatcher::class));

        $this->assertNull($fails->fresh()->igdb_id);
        $this->assertSame(72, $matches->fresh()->igdb_id);
    }

    public function test_deleted_connection_is_a_no_op(): void
    {
        Http::fake();

        (new MatchConnectionIgdb(999999))->handle(app(\App\Services\Igdb\GameMatcher::class));

        Http::assertNothingSent();
    }

    /**
     * V39: rate limit enforced at the queue layer via middleware.
     */
    public function test_job_is_rate_limited_on_the_igdb_sync_limiter(): void
    {
        $middleware = (new MatchConnectionIgdb(1))->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(RateLimited::class, $middleware[0]);
    }
}
