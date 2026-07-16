<?php

namespace Tests\Feature\Connections;

use App\Jobs\SyncConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CreateXboxConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT_URI = 'https://gamebower.test/connections/xbox/callback';

    private function fakeTokenExchange(): void
    {
        Http::fake([
            'login.microsoftonline.com/consumers/oauth2/v2.0/token*' => Http::response([
                'access_token' => 'ms-access-token',
                'refresh_token' => 'ms-refresh-token',
                'expires_in' => 3600,
            ]),
            'user.auth.xboxlive.com/user/authenticate*' => Http::response([
                'Token' => 'xbl-user-token',
                'DisplayClaims' => ['xui' => [['uhs' => 'user-hash-123']]],
            ]),
            'xsts.auth.xboxlive.com/xsts/authorize*' => Http::response([
                'Token' => 'xsts-token',
                'DisplayClaims' => ['xui' => [['uhs' => 'user-hash-123', 'xid' => '2669321029139235']]],
            ]),
        ]);
    }

    public function test_xbox_connection_created_from_oauth_code(): void
    {
        Queue::fake();
        $this->fakeTokenExchange();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/connections', [
            'platform' => 'xbox',
            'code' => 'oauth-code-from-ms-login',
            'redirect_uri' => self::REDIRECT_URI,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('platform_connections', [
            'user_id' => $user->id,
            'platform' => 'xbox',
            'external_account_id' => '2669321029139235',
            'status' => 'pending',
        ]);
    }

    /**
     * V2: tokens are encrypted at rest — raw column value never equals the
     * plaintext token.
     */
    public function test_xbox_tokens_stored_encrypted(): void
    {
        Queue::fake();
        $this->fakeTokenExchange();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/connections', [
            'platform' => 'xbox',
            'code' => 'oauth-code-from-ms-login',
            'redirect_uri' => self::REDIRECT_URI,
        ])->assertCreated();

        $raw = DB::table('platform_connections')->where('platform', 'xbox')->first();
        $this->assertNotNull($raw->auth_token);
        $this->assertNotNull($raw->refresh_token);
        $this->assertNotSame('ms-access-token', $raw->auth_token);
        $this->assertNotSame('ms-refresh-token', $raw->refresh_token);
        $this->assertNotNull($raw->token_expires_at);
    }

    /**
     * V8: connect dispatches the sync job — never syncs inline.
     */
    public function test_xbox_connect_dispatches_sync_job(): void
    {
        Queue::fake();
        $this->fakeTokenExchange();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/connections', [
            'platform' => 'xbox',
            'code' => 'oauth-code-from-ms-login',
            'redirect_uri' => self::REDIRECT_URI,
        ])->assertCreated();

        Queue::assertPushed(SyncConnection::class);
    }

    public function test_xbox_requires_code_and_redirect_uri(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/connections', [
            'platform' => 'xbox',
        ])->assertUnprocessable()->assertJsonValidationErrors(['code', 'redirect_uri']);
    }

    public function test_invalid_xbox_code_rejected(): void
    {
        Http::fake([
            'login.microsoftonline.com/consumers/oauth2/v2.0/token*' => Http::response(['error' => 'invalid_grant'], 400),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/connections', [
            'platform' => 'xbox',
            'code' => 'bad-code',
            'redirect_uri' => self::REDIRECT_URI,
        ])->assertUnprocessable()->assertJsonValidationErrors(['code']);
    }

    public function test_xsts_rejection_rejects_connect(): void
    {
        Http::fake([
            'login.microsoftonline.com/consumers/oauth2/v2.0/token*' => Http::response([
                'access_token' => 'ms-access-token',
                'refresh_token' => 'ms-refresh-token',
                'expires_in' => 3600,
            ]),
            'user.auth.xboxlive.com/user/authenticate*' => Http::response([
                'Token' => 'xbl-user-token',
                'DisplayClaims' => ['xui' => [['uhs' => 'user-hash-123']]],
            ]),
            'xsts.auth.xboxlive.com/xsts/authorize*' => Http::response(['XErr' => 2148916233], 401),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/connections', [
            'platform' => 'xbox',
            'code' => 'oauth-code-from-ms-login',
            'redirect_uri' => self::REDIRECT_URI,
        ])->assertUnprocessable()->assertJsonValidationErrors(['code']);

        $this->assertDatabaseCount('platform_connections', 0);
    }
}
