<?php

namespace App\Services\Gog;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GogClient
{
    private const AUTH_URL = 'https://auth.gog.com/token';

    private const EMBED_URL = 'https://embed.gog.com';

    /**
     * GOG has no per-app redirect registration; community-documented clients
     * use the embed success page as the redirect target (gogapidocs).
     */
    private const REDIRECT_URI = 'https://embed.gog.com/on_login_success?origin=client';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
    }

    /**
     * Exchange an OAuth authorization code for tokens, or null when GOG
     * rejects the code.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, user_id: string}|null
     */
    public function exchangeCode(string $code): ?array
    {
        return $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECT_URI,
        ]);
    }

    /**
     * V14: trade a refresh token for a fresh token set, or null when the
     * refresh token has been revoked.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, user_id: string}|null
     */
    public function refreshTokens(string $refreshToken): ?array
    {
        return $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * Fetch every owned product across the paginated embed API.
     *
     * @return list<array<string, mixed>>
     */
    public function getOwnedGames(string $accessToken): array
    {
        $products = [];
        $page = 1;

        do {
            $response = Http::withToken($accessToken)
                ->get(self::EMBED_URL.'/account/getFilteredProducts', [
                    'mediaType' => 1,
                    'page' => $page,
                ]);

            if ($response->failed()) {
                throw new RuntimeException('GOG getFilteredProducts request failed: '.$response->status());
            }

            $products = [...$products, ...$response->json('products', [])];
            $totalPages = (int) $response->json('totalPages', 1);
            $page++;
        } while ($page <= $totalPages);

        return $products;
    }

    /**
     * @param  array<string, string>  $params
     * @return array{access_token: string, refresh_token: string, expires_in: int, user_id: string}|null
     */
    private function requestToken(array $params): ?array
    {
        $response = Http::asForm()->post(self::AUTH_URL, [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            ...$params,
        ]);

        if ($response->status() === 400 || $response->status() === 401) {
            return null;
        }

        if ($response->failed()) {
            throw new RuntimeException('GOG token request failed: '.$response->status());
        }

        return [
            'access_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'expires_in' => (int) $response->json('expires_in'),
            'user_id' => (string) $response->json('user_id'),
        ];
    }
}
