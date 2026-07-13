<?php

namespace Tests\Feature\Connections;

use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListConnectionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * V9: sync status always visible per connection.
     */
    public function test_lists_connections_with_sync_status(): void
    {
        $user = User::factory()->create();
        PlatformConnection::factory()->create([
            'user_id' => $user->id,
            'status' => 'ok',
            'last_synced_at' => '2026-07-12 08:00:00',
        ]);

        $this->withToken($user->createToken('t')->plainTextToken)
            ->getJson('/api/connections')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonStructure([
                ['id', 'platform', 'last_synced_at', 'status'],
            ]);
    }

    public function test_does_not_list_other_users_connections(): void
    {
        $user = User::factory()->create();
        PlatformConnection::factory()->create();

        $this->withToken($user->createToken('t')->plainTextToken)
            ->getJson('/api/connections')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_does_not_expose_tokens(): void
    {
        $user = User::factory()->create();
        PlatformConnection::factory()->create([
            'user_id' => $user->id,
            'auth_token' => 'super-secret-token',
        ]);

        $json = $this->withToken($user->createToken('t')->plainTextToken)
            ->getJson('/api/connections')
            ->json();

        $this->assertArrayNotHasKey('auth_token', $json[0]);
        $this->assertArrayNotHasKey('refresh_token', $json[0]);
    }
}
