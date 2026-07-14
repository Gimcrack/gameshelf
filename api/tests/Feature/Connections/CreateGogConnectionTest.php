<?php

namespace Tests\Feature\Connections;

use App\Jobs\SyncConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CreateGogConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function fakeTokenExchange(): void
    {
        Http::fake([
            'auth.gog.com/token*' => Http::response([
                'access_token' => 'gog-access-token',
                'refresh_token' => 'gog-refresh-token',
                'expires_in' => 3600,
                'user_id' => '48628349957132247',
            ]),
        ]);
    }

    public function test_gog_connection_created_from_oauth_code(): void
    {
        Queue::fake();
        $this->fakeTokenExchange();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/connections', [
            'platform' => 'gog',
            'code' => 'oauth-code-from-gog-login',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('platform_connections', [
            'user_id' => $user->id,
            'platform' => 'gog',
            'external_account_id' => '48628349957132247',
            'status' => 'pending',
        ]);
    }

    /**
     * V2: tokens are encrypted at rest — raw column value never equals the
     * plaintext token.
     */
    public function test_gog_tokens_stored_encrypted(): void
    {
        Queue::fake();
        $this->fakeTokenExchange();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/connections', [
            'platform' => 'gog',
            'code' => 'oauth-code-from-gog-login',
        ])->assertCreated();

        $raw = DB::table('platform_connections')->where('platform', 'gog')->first();
        $this->assertNotNull($raw->auth_token);
        $this->assertNotNull($raw->refresh_token);
        $this->assertNotSame('gog-access-token', $raw->auth_token);
        $this->assertNotSame('gog-refresh-token', $raw->refresh_token);
        $this->assertNotNull($raw->token_expires_at);
    }

    /**
     * V8: connect dispatches the sync job — never syncs inline.
     */
    public function test_gog_connect_dispatches_sync_job(): void
    {
        Queue::fake();
        $this->fakeTokenExchange();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/connections', [
            'platform' => 'gog',
            'code' => 'oauth-code-from-gog-login',
        ])->assertCreated();

        Queue::assertPushed(SyncConnection::class);
    }

    public function test_gog_requires_code(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/connections', [
            'platform' => 'gog',
        ])->assertUnprocessable()->assertJsonValidationErrors(['code']);
    }

    public function test_invalid_code_rejected(): void
    {
        Http::fake([
            'auth.gog.com/token*' => Http::response(['error' => 'invalid_grant'], 400),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/connections', [
            'platform' => 'gog',
            'code' => 'bad-code',
        ])->assertUnprocessable()->assertJsonValidationErrors(['code']);
    }

    public function test_steam_connect_flow_unchanged(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/connections', [
            'platform' => 'steam',
            'steam_id' => '76561197960287930',
        ])->assertCreated();

        Queue::assertPushed(SyncConnection::class);
    }
}
