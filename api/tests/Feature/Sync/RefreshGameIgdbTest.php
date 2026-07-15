<?php

namespace Tests\Feature\Sync;

use App\Jobs\RefreshGameIgdb;
use App\Models\Game;
use App\Services\Library\GameIgdbRefresh;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RefreshGameIgdbTest extends TestCase
{
    use RefreshDatabase;

    /**
     * T32/V39: child job refreshes exactly one already-matched game.
     */
    public function test_refreshes_one_matched_game(): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([
                ['id' => 1942, 'name' => 'The Witcher 3 (Updated)', 'genres' => []],
            ]),
            'api.igdb.com/v4/game_time_to_beats' => Http::response([]),
        ]);
        $game = Game::create(['igdb_id' => 1942, 'title' => 'The Witcher 3 (Stale)']);

        (new RefreshGameIgdb($game->id))->handle(app(GameIgdbRefresh::class));

        $this->assertSame('The Witcher 3 (Updated)', $game->fresh()->title);
    }

    public function test_deleted_game_is_a_no_op(): void
    {
        Http::fake();

        (new RefreshGameIgdb(999999))->handle(app(GameIgdbRefresh::class));

        Http::assertNothingSent();
    }

    /**
     * V35 boundary: a game that lost/never had an igdb_id is skipped —
     * refresh requires an existing match.
     */
    public function test_provisional_game_is_a_no_op(): void
    {
        Http::fake();
        $game = Game::create(['title' => 'Provisional']);

        (new RefreshGameIgdb($game->id))->handle(app(GameIgdbRefresh::class));

        Http::assertNothingSent();
        $this->assertNull($game->fresh()->igdb_id);
    }

    /**
     * V39: rate limit enforced at the queue layer via middleware.
     */
    public function test_job_is_rate_limited_on_the_igdb_sync_limiter(): void
    {
        $middleware = (new RefreshGameIgdb(1))->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(RateLimited::class, $middleware[0]);
    }
}
