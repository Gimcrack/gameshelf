<?php

namespace Tests\Feature\Library;

use App\Jobs\SyncConnection;
use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Models\UserGameMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GameMetaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->withToken($this->user->createToken('t')->plainTextToken);
    }

    private function ownedGame(string $title = 'Portal 2'): Game
    {
        $game = Game::create(['title' => $title]);
        $connection = PlatformConnection::factory()->create([
            'user_id' => $this->user->id,
            'platform' => 'steam',
            'status' => 'ok',
        ]);
        OwnedGame::create([
            'user_id' => $this->user->id,
            'platform_connection_id' => $connection->id,
            'game_id' => $game->id,
            'platform_game_id' => '620',
            'playtime_minutes' => 1200,
            'added_at' => now(),
        ]);

        return $game;
    }

    public function test_meta_upsert_creates_then_updates_single_row(): void
    {
        $game = $this->ownedGame();

        $this->putJson("/api/library/{$game->id}/meta", [
            'status' => 'playing',
            'tags' => ['co-op', 'favorite'],
        ])->assertOk()->assertJsonPath('status', 'playing');

        $this->putJson("/api/library/{$game->id}/meta", [
            'status' => 'finished',
            'rating' => 5,
        ])->assertOk()->assertJsonPath('status', 'finished');

        $this->assertDatabaseCount('user_game_meta', 1);
        $meta = UserGameMeta::first();
        $this->assertSame('finished', $meta->status->value);
        $this->assertSame(5, $meta->rating);
        // Partial update: untouched fields persist.
        $this->assertSame(['co-op', 'favorite'], $meta->tags);
    }

    public function test_meta_validation(): void
    {
        $game = $this->ownedGame();

        $this->putJson("/api/library/{$game->id}/meta", [
            'status' => 'not-a-status',
        ])->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $this->putJson("/api/library/{$game->id}/meta", [
            'rating' => 11,
        ])->assertUnprocessable()->assertJsonValidationErrors(['rating']);
    }

    public function test_meta_rejected_for_games_not_in_library(): void
    {
        $otherGame = Game::create(['title' => 'Not Owned']);

        $this->putJson("/api/library/{$otherGame->id}/meta", [
            'status' => 'playing',
        ])->assertNotFound();
    }

    /**
     * V6: platform re-sync never touches user meta.
     */
    public function test_meta_survives_resync(): void
    {
        Http::fake([
            'api.steampowered.com/IPlayerService/GetOwnedGames/*' => Http::response([
                'response' => [
                    'game_count' => 1,
                    'games' => [['appid' => 620, 'name' => 'Portal 2', 'playtime_forever' => 1200]],
                ],
            ]),
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/**' => Http::response([]),
        ]);
        $connection = PlatformConnection::factory()->create([
            'user_id' => $this->user->id,
            'platform' => 'steam',
            'status' => 'ok',
        ]);

        (new SyncConnection($connection->id))->handle();
        $game = Game::firstOrFail();

        $this->putJson("/api/library/{$game->id}/meta", [
            'status' => 'abandoned',
            'tags' => ['someday'],
            'notes' => 'Bounced off the water temple.',
            'rating' => 3,
        ])->assertOk();

        (new SyncConnection($connection->id))->handle();

        $meta = UserGameMeta::firstOrFail();
        $this->assertSame('abandoned', $meta->status->value);
        $this->assertSame(['someday'], $meta->tags);
        $this->assertSame('Bounced off the water temple.', $meta->notes);
        $this->assertSame(3, $meta->rating);
        $this->assertDatabaseCount('user_game_meta', 1);
    }

    public function test_library_entries_include_meta(): void
    {
        $game = $this->ownedGame();

        $this->putJson("/api/library/{$game->id}/meta", [
            'status' => 'playing',
            'tags' => ['co-op'],
            'rating' => 4,
        ])->assertOk();

        $entry = $this->getJson('/api/library')->assertOk()->json()[0];

        $this->assertSame('playing', $entry['status']);
        $this->assertSame(['co-op'], $entry['tags']);
        $this->assertSame(4, $entry['rating']);
    }
}
