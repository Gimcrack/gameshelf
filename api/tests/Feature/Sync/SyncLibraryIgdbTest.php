<?php

namespace Tests\Feature\Sync;

use App\Jobs\SyncLibraryIgdb;
use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
     * T31/V38: matches provisional games across every connection AND
     * refreshes already-matched games' canonical attrs, in one run.
     */
    public function test_matches_provisional_and_refreshes_matched_games(): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::sequence()
                // GameMatcher's search for the provisional game.
                ->push([['id' => 72, 'name' => 'Portal 2', 'genres' => []]])
                // GameIgdbRefresh's getGame for the already-matched game.
                ->push([['id' => 1942, 'name' => 'The Witcher 3 (Updated)', 'genres' => []]]),
            'api.igdb.com/v4/game_time_to_beats' => Http::response([]),
        ]);
        $user = User::factory()->create();
        $connection = PlatformConnection::factory()->create(['user_id' => $user->id, 'status' => 'ok']);

        $provisional = Game::create(['title' => 'Some Weird Title']);
        $this->own($connection, $provisional, '620');

        $matched = Game::create(['igdb_id' => 1942, 'title' => 'The Witcher 3 (Stale)']);
        $this->own($connection, $matched, '292030');

        (new SyncLibraryIgdb($user->id))->handle();

        $this->assertSame(72, $provisional->fresh()->igdb_id);
        $this->assertSame('The Witcher 3 (Updated)', $matched->fresh()->title);
    }

    /**
     * V26 (extended by V38): one game's IGDB failure never aborts the rest
     * of the batch.
     */
    public function test_one_game_failure_does_not_abort_the_rest(): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::sequence()
                ->push(null, 500) // matcher search for the provisional game fails
                ->push([['id' => 1942, 'name' => 'The Witcher 3 (Updated)', 'genres' => []]]),
            'api.igdb.com/v4/game_time_to_beats' => Http::response([]),
        ]);
        $user = User::factory()->create();
        $connection = PlatformConnection::factory()->create(['user_id' => $user->id, 'status' => 'ok']);

        $provisional = Game::create(['title' => 'Fails To Match']);
        $this->own($connection, $provisional, '111');

        $matched = Game::create(['igdb_id' => 1942, 'title' => 'The Witcher 3 (Stale)']);
        $this->own($connection, $matched, '292030');

        (new SyncLibraryIgdb($user->id))->handle();

        $this->assertNull($provisional->fresh()->igdb_id);
        $this->assertSame('The Witcher 3 (Updated)', $matched->fresh()->title);
    }

    /**
     * V38: only the caller's own games are touched.
     */
    public function test_only_syncs_the_given_users_games(): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([]),
        ]);
        $user = User::factory()->create();
        $other = User::factory()->create();
        $othersConnection = PlatformConnection::factory()->create(['user_id' => $other->id, 'status' => 'ok']);
        $othersGame = Game::create(['igdb_id' => 1942, 'title' => 'Not Mine']);
        $this->own($othersConnection, $othersGame, '1');

        (new SyncLibraryIgdb($user->id))->handle();

        $this->assertSame('Not Mine', $othersGame->fresh()->title);
    }
}
