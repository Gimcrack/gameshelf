<?php

namespace App\Services\Igdb;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * IGDB authenticates with a Twitch app access token (client credentials).
 * The token is cached until shortly before expiry (§C: Redis in prod).
 */
class TwitchAuth
{
    private const CACHE_KEY = 'igdb:app-token';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
    }

    public function token(): string
    {
        $cached = Cache::get(self::CACHE_KEY);

        if ($cached !== null) {
            return $cached;
        }

        $response = Http::asForm()->post('https://id.twitch.tv/oauth2/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'client_credentials',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Twitch app token request failed: '.$response->status());
        }

        $token = $response->json('access_token');
        $expiresIn = (int) $response->json('expires_in');

        Cache::put(self::CACHE_KEY, $token, max($expiresIn - 60, 60));

        return $token;
    }
}
