<?php

namespace Tests\Feature\Igdb;

use App\Services\Igdb\TwitchAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TwitchAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_fetches_and_caches_app_token(): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response([
                'access_token' => 'twitch-app-token',
                'expires_in' => 3600,
                'token_type' => 'bearer',
            ]),
        ]);

        $auth = app(TwitchAuth::class);

        $this->assertSame('twitch-app-token', $auth->token());
        $this->assertSame('twitch-app-token', $auth->token());

        Http::assertSentCount(1);
    }

    public function test_failed_token_request_throws(): void
    {
        Http::fake([
            'id.twitch.tv/oauth2/token*' => Http::response(['message' => 'invalid client'], 403),
        ]);

        $this->expectException(\RuntimeException::class);

        app(TwitchAuth::class)->token();
    }
}
