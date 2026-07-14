<?php

namespace Tests\Feature\Library;

use App\Jobs\SyncConnection;
use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManualAddTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->withToken($this->user->createToken('api')->plainTextToken);
    }

    private function fakeIgdbGame(): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([
                [
                    'id' => 1942,
                    'name' => 'The Witcher 3: Wild Hunt',
                    'cover' => ['url' => '//images.igdb.com/witcher3.jpg'],
                    'genres' => [['name' => 'RPG']],
                    'first_release_date' => 1431993600,
                ],
            ]),
            'api.igdb.com/v4/game_time_to_beats' => Http::response([
                ['id' => 7, 'game_id' => 1942, 'normally' => 180000],
            ]),
        ]);
    }

    public function test_manual_add_creates_game_and_owned_row(): void
    {
        $this->fakeIgdbGame();

        $this->postJson('/api/library', ['igdb_id' => 1942])
            ->assertCreated()
            ->assertJsonPath('title', 'The Witcher 3: Wild Hunt');

        $this->assertDatabaseHas('games', ['igdb_id' => 1942, 'time_to_beat_minutes' => 3000]);
        $this->assertDatabaseHas('platform_connections', [
            'user_id' => $this->user->id,
            'platform' => 'manual',
        ]);
        $this->assertDatabaseHas('owned_games', [
            'user_id' => $this->user->id,
            'platform_game_id' => '1942',
            'playtime_minutes' => null,
        ]);
    }

    /**
     * V7: existing canonical game reused, no IGDB round-trip duplication.
     */
    public function test_manual_add_reuses_existing_canonical_game(): void
    {
        $this->fakeIgdbGame();
        $existing = Game::create(['igdb_id' => 1942, 'title' => 'The Witcher 3: Wild Hunt']);

        $this->postJson('/api/library', ['igdb_id' => 1942])->assertCreated();

        $this->assertSame(1, Game::count());
        $this->assertDatabaseHas('owned_games', ['game_id' => $existing->id]);
    }

    /**
     * V19 + V10: repeat manual add never duplicates rows.
     */
    public function test_duplicate_manual_add_returns_existing(): void
    {
        $this->fakeIgdbGame();

        $this->postJson('/api/library', ['igdb_id' => 1942])->assertCreated();
        $this->postJson('/api/library', ['igdb_id' => 1942])->assertOk();

        $this->assertDatabaseCount('owned_games', 1);
        $this->assertSame(1, PlatformConnection::where('platform', 'manual')->count());
    }

    public function test_platform_owned_game_not_duplicated_manually(): void
    {
        $this->fakeIgdbGame();
        $game = Game::create(['igdb_id' => 1942, 'title' => 'The Witcher 3: Wild Hunt']);
        $steam = PlatformConnection::factory()->create([
            'user_id' => $this->user->id,
            'platform' => 'steam',
            'status' => 'ok',
        ]);
        OwnedGame::create([
            'user_id' => $this->user->id,
            'platform_connection_id' => $steam->id,
            'game_id' => $game->id,
            'platform_game_id' => '292030',
            'playtime_minutes' => 900,
            'added_at' => now(),
        ]);

        $this->postJson('/api/library', ['igdb_id' => 1942])->assertOk();

        $this->assertDatabaseCount('owned_games', 1);
        $this->assertSame(0, PlatformConnection::where('platform', 'manual')->count());
    }

    public function test_unknown_igdb_id_rejected(): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
            ]),
            'api.igdb.com/v4/games' => Http::response([]),
        ]);

        $this->postJson('/api/library', ['igdb_id' => 999999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['igdb_id']);
    }

    public function test_manual_delete_removes_only_manual_row(): void
    {
        $this->fakeIgdbGame();
        $this->postJson('/api/library', ['igdb_id' => 1942])->assertCreated();
        $game = Game::where('igdb_id', 1942)->firstOrFail();

        // Same game also synced from steam.
        $steam = PlatformConnection::factory()->create([
            'user_id' => $this->user->id,
            'platform' => 'steam',
            'status' => 'ok',
        ]);
        OwnedGame::create([
            'user_id' => $this->user->id,
            'platform_connection_id' => $steam->id,
            'game_id' => $game->id,
            'platform_game_id' => '292030',
            'playtime_minutes' => 900,
            'added_at' => now(),
        ]);

        $this->deleteJson("/api/library/{$game->id}/manual")->assertNoContent();

        $this->assertDatabaseCount('owned_games', 1);
        $this->assertDatabaseHas('owned_games', ['platform_connection_id' => $steam->id]);
    }

    public function test_manual_delete_404_when_no_manual_entry(): void
    {
        $game = Game::create(['igdb_id' => 1942, 'title' => 'The Witcher 3: Wild Hunt']);

        $this->deleteJson("/api/library/{$game->id}/manual")->assertNotFound();
    }

    /**
     * V19: manual connections never sync and stay out of the connections UI.
     */
    public function test_manual_connection_hidden_and_unsynced(): void
    {
        $this->fakeIgdbGame();
        $this->postJson('/api/library', ['igdb_id' => 1942])->assertCreated();

        $platforms = array_column($this->getJson('/api/connections')->assertOk()->json(), 'platform');
        $this->assertNotContains('manual', $platforms);

        $manual = PlatformConnection::where('platform', 'manual')->firstOrFail();
        (new SyncConnection($manual->id))->handle();

        // No-op: status untouched, nothing ingested beyond the manual row.
        $this->assertSame('ok', $manual->fresh()->status->value);
        $this->assertDatabaseCount('owned_games', 1);
    }
}
