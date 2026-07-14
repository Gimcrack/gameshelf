<?php

namespace App\Services\Gog;

use App\Models\PlatformConnection;
use Illuminate\Support\Facades\Date;
use RuntimeException;

class GogTokenManager
{
    /**
     * Refresh this long before nominal expiry so a token never dies
     * mid-operation (V14).
     */
    private const EXPIRY_BUFFER_SECONDS = 120;

    public function __construct(private readonly GogClient $client)
    {
    }

    /**
     * V14: refresh before expiry, persisting the rotated token pair through
     * the encrypted casts (V2).
     */
    public function freshAccessToken(PlatformConnection $connection): string
    {
        $expiresSoon = $connection->token_expires_at === null
            || $connection->token_expires_at->subSeconds(self::EXPIRY_BUFFER_SECONDS)->isPast();

        if (! $expiresSoon) {
            return $connection->auth_token;
        }

        if ($connection->refresh_token === null) {
            throw new RuntimeException('GOG connection has no refresh token to renew with.');
        }

        $tokens = $this->client->refreshTokens($connection->refresh_token);

        if ($tokens === null) {
            throw new RuntimeException('GOG refused the refresh token; user must reconnect.');
        }

        $connection->update([
            'auth_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_expires_at' => Date::now()->addSeconds($tokens['expires_in']),
        ]);

        return $tokens['access_token'];
    }
}
