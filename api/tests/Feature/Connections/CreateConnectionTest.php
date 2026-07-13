<?php

namespace Tests\Feature\Connections;

use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CreateConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function authed(): User
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('t')->plainTextToken);

        return $user;
    }

    public function test_connects_steam_with_steam_id(): void
    {
        Queue::fake();
        $this->authed();

        $response = $this->postJson('/api/connections', [
            'platform' => 'steam',
            'steam_id' => '76561197960287930',
        ]);

        $response->assertCreated()
            ->assertJsonPath('platform', 'steam')
            ->assertJsonPath('status', 'pending');

        $this->assertDatabaseHas('platform_connections', [
            'platform' => 'steam',
            'external_account_id' => '76561197960287930',
        ]);
    }

    public function test_connects_steam_with_vanity_url(): void
    {
        Queue::fake();
        Http::fake([
            'api.steampowered.com/ISteamUser/ResolveVanityURL/*' => Http::response([
                'response' => ['success' => 1, 'steamid' => '76561197960287930'],
            ]),
        ]);
        $this->authed();

        $response = $this->postJson('/api/connections', [
            'platform' => 'steam',
            'vanity_url' => 'gabelogannewell',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('platform_connections', [
            'external_account_id' => '76561197960287930',
        ]);
    }

    public function test_unresolvable_vanity_url_rejected(): void
    {
        Http::fake([
            'api.steampowered.com/ISteamUser/ResolveVanityURL/*' => Http::response([
                'response' => ['success' => 42, 'message' => 'No match'],
            ]),
        ]);
        $this->authed();

        $this->postJson('/api/connections', [
            'platform' => 'steam',
            'vanity_url' => 'nobody-here',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['vanity_url']);
    }

    public function test_steam_requires_identity(): void
    {
        $this->authed();

        $this->postJson('/api/connections', ['platform' => 'steam'])
            ->assertUnprocessable();
    }

    public function test_unsupported_platform_rejected(): void
    {
        $this->authed();

        $this->postJson('/api/connections', ['platform' => 'epic'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['platform']);
    }

    public function test_requires_auth(): void
    {
        $this->postJson('/api/connections', ['platform' => 'steam'])
            ->assertUnauthorized();
    }

    /**
     * V2: auth tokens are encrypted at rest.
     */
    public function test_auth_token_encrypted_at_rest(): void
    {
        $connection = PlatformConnection::factory()->create([
            'auth_token' => 'super-secret-token',
        ]);

        $raw = DB::table('platform_connections')
            ->where('id', $connection->id)
            ->value('auth_token');

        $this->assertNotSame('super-secret-token', $raw);
        $this->assertSame('super-secret-token', $connection->fresh()->auth_token);
    }
}
