<?php

namespace Tests\Feature\Sync;

use App\Jobs\MatchConnectionIgdb;
use App\Jobs\RefreshGameIgdb;
use App\Jobs\SyncLibraryIgdb;
use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncLibraryIgdbTest extends TestCase
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
     * T32/V39 (B8): the orchestrator makes no IGDB calls itself — it fans
     * out one MatchConnectionIgdb per connection and one RefreshGameIgdb
     * per already-matched game.
     */
    public function test_fans_out_match_and_refresh_jobs(): void
    {
        Queue::fake();
        Http::fake();
        $user = User::factory()->create();
        $connection = PlatformConnection::factory()->create(['user_id' => $user->id, 'status' => 'ok']);

        $provisional = Game::create(['title' => 'Some Weird Title']);
        $this->own($connection, $provisional, '620');

        $matched = Game::create(['igdb_id' => 1942, 'title' => 'The Witcher 3']);
        $this->own($connection, $matched, '292030');

        (new SyncLibraryIgdb($user->id))->handle();

        Http::assertNothingSent();
        Queue::assertPushed(MatchConnectionIgdb::class, fn ($job) => $job->connectionId === $connection->id);
        Queue::assertPushed(RefreshGameIgdb::class, fn ($job) => $job->gameId === $matched->id);
        Queue::assertNotPushed(RefreshGameIgdb::class, fn ($job) => $job->gameId === $provisional->id);
    }

    /**
     * V39: a game matched during this same pass gets full canonical data
     * from that fetch — no refresh job dispatched for it (no duplicate
     * IGDB volume). Provisional games ride their connection's match job.
     */
    public function test_provisional_games_get_no_refresh_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $connection = PlatformConnection::factory()->create(['user_id' => $user->id, 'status' => 'ok']);

        $provisional = Game::create(['title' => 'Unmatched']);
        $this->own($connection, $provisional, '111');

        (new SyncLibraryIgdb($user->id))->handle();

        Queue::assertPushed(MatchConnectionIgdb::class, 1);
        Queue::assertNotPushed(RefreshGameIgdb::class);
    }

    /**
     * V38: only the caller's own connections and games fan out.
     */
    public function test_only_fans_out_the_given_users_games(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $othersConnection = PlatformConnection::factory()->create(['user_id' => $other->id, 'status' => 'ok']);
        $othersGame = Game::create(['igdb_id' => 1942, 'title' => 'Not Mine']);
        $this->own($othersConnection, $othersGame, '1');

        (new SyncLibraryIgdb($user->id))->handle();

        Queue::assertNotPushed(MatchConnectionIgdb::class);
        Queue::assertNotPushed(RefreshGameIgdb::class);
    }

    public function test_missing_user_dispatches_nothing(): void
    {
        Queue::fake();

        (new SyncLibraryIgdb(999999))->handle();

        Queue::assertNotPushed(MatchConnectionIgdb::class);
        Queue::assertNotPushed(RefreshGameIgdb::class);
    }
}
