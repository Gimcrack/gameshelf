<?php

namespace App\Services\Xbox;

use App\Models\PlatformConnection;
use Illuminate\Support\Facades\Date;
use RuntimeException;

class XboxTokenManager
{
    /**
     * Refresh this long before nominal expiry so a token never dies
     * mid-operation (V14, mirrors GogTokenManager).
     */
    private const EXPIRY_BUFFER_SECONDS = 120;

    public function __construct(private readonly XboxClient $client)
    {
    }

    /**
     * V14: refresh the MS access token before expiry, persisting the
     * rotated pair through the encrypted casts (V2) — then re-derive XBL/XSTS
     * credentials fresh every call, since those tokens are short-lived and
     * never persisted (only the long-lived MS refresh token is).
     *
     * @return array{xsts_token: string, user_hash: string, xuid: string}
     */
    public function freshXstsCredentials(PlatformConnection $connection): array
    {
        $accessToken = $this->freshAccessToken($connection);

        $credentials = $this->client->exchangeForXsts($accessToken);

        if ($credentials === null) {
            throw new RuntimeException('Xbox rejected the XSTS exchange; user must reconnect.');
        }

        return $credentials;
    }

    private function freshAccessToken(PlatformConnection $connection): string
    {
        $expiresSoon = $connection->token_expires_at === null
            || $connection->token_expires_at->subSeconds(self::EXPIRY_BUFFER_SECONDS)->isPast();

        if (! $expiresSoon) {
            return $connection->auth_token;
        }

        if ($connection->refresh_token === null) {
            throw new RuntimeException('Xbox connection has no refresh token to renew with.');
        }

        $tokens = $this->client->refreshTokens($connection->refresh_token);

        if ($tokens === null) {
            throw new RuntimeException('Microsoft refused the refresh token; user must reconnect.');
        }

        $connection->update([
            'auth_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_expires_at' => Date::now()->addSeconds($tokens['expires_in']),
        ]);

        return $tokens['access_token'];
    }
}
