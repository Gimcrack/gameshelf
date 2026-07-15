<?php

namespace Tests\Feature\Library;

use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IgdbRefreshTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->withToken($this->user->createToken('api')->plainTextToken);
    }

    private function own(Game $game): OwnedGame
    {
        $connection = PlatformConnection::factory()->create(['user_id' => $this->user->id, 'status' => 'ok']);

        return OwnedGame::create([
            'user_id' => $this->user->id,
            'platform_connection_id' => $connection->id,
            'game_id' => $game->id,
            'platform_game_id' => 'x',
            'added_at' => now(),
        ]);
    }

    public function test_refresh_reapplies_current_igdb_data(): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([
                ['id' => 700, 'name' => 'Portal 2 (Updated)', 'genres' => [['name' => 'Puzzle']]],
            ]),
            'api.igdb.com/v4/game_time_to_beats' => Http::response([
                ['id' => 1, 'game_id' => 700, 'normally' => 3600],
            ]),
        ]);
        $game = Game::create(['igdb_id' => 700, 'title' => 'Portal 2 (Stale)']);
        $this->own($game);

        $response = $this->postJson("/api/library/{$game->id}/refresh-igdb")->assertOk();

        $this->assertSame('Portal 2 (Updated)', $response->json('title'));
        $this->assertSame(['Puzzle'], $response->json('genres'));
        $this->assertSame(60, $response->json('time_to_beat_minutes'));
        $this->assertDatabaseHas('games', ['id' => $game->id, 'title' => 'Portal 2 (Updated)']);
    }

    /**
     * V35: no igdb_id yet — 422, direct user to fix-match first.
     */
    public function test_refresh_422_when_not_yet_matched(): void
    {
        $game = Game::create(['title' => 'Provisional']);
        $this->own($game);

        $this->postJson("/api/library/{$game->id}/refresh-igdb")->assertUnprocessable();
    }

    public function test_refresh_404_when_igdb_no_longer_knows_the_id(): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([]),
        ]);
        $game = Game::create(['igdb_id' => 701, 'title' => 'Vanished']);
        $this->own($game);

        $this->postJson("/api/library/{$game->id}/refresh-igdb")->assertNotFound();
    }

    public function test_refresh_404_when_game_not_owned(): void
    {
        $game = Game::create(['igdb_id' => 702, 'title' => 'Not Mine']);

        $this->postJson("/api/library/{$game->id}/refresh-igdb")->assertNotFound();
    }

    public function test_refresh_requires_auth(): void
    {
        $game = Game::create(['igdb_id' => 703, 'title' => 'X']);

        $this->withHeaders(['Authorization' => ''])
            ->postJson("/api/library/{$game->id}/refresh-igdb")
            ->assertUnauthorized();
    }
}
