<?php

namespace Tests\Feature\Connections;

use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisconnectTest extends TestCase
{
    use RefreshDatabase;

    /**
     * V13: disconnect is soft — owned games persist, status flips to
     * disconnected, nothing is deleted.
     */
    public function test_disconnect_keeps_owned_games(): void
    {
        $user = User::factory()->create();
        $connection = PlatformConnection::factory()->create([
            'user_id' => $user->id,
            'status' => 'ok',
        ]);
        $game = Game::create(['title' => 'Portal 2']);
        OwnedGame::create([
            'user_id' => $user->id,
            'platform_connection_id' => $connection->id,
            'game_id' => $game->id,
            'platform_game_id' => '620',
            'playtime_minutes' => 100,
            'added_at' => now(),
        ]);

        $this->withToken($user->createToken('t')->plainTextToken)
            ->deleteJson("/api/connections/{$connection->id}")
            ->assertOk()
            ->assertJsonPath('status', 'disconnected');

        $this->assertDatabaseCount('owned_games', 1);
        $this->assertDatabaseCount('platform_connections', 1);
        $this->assertSame('disconnected', $connection->fresh()->status->value);
    }

    public function test_cannot_disconnect_other_users_connection(): void
    {
        $user = User::factory()->create();
        $other = PlatformConnection::factory()->create();

        $this->withToken($user->createToken('t')->plainTextToken)
            ->deleteJson("/api/connections/{$other->id}")
            ->assertNotFound();

        $this->assertNotSame('disconnected', $other->fresh()->status->value);
    }
}
