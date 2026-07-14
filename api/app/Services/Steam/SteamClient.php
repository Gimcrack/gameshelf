<?php

namespace App\Services\Steam;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class SteamClient
{
    private const BASE_URL = 'https://api.steampowered.com';

    public function __construct(private readonly string $apiKey)
    {
    }

    /**
     * Resolve a Steam vanity URL name to a SteamID64, or null when no match.
     */
    public function resolveVanityUrl(string $vanityName): ?string
    {
        $response = Http::get(self::BASE_URL.'/ISteamUser/ResolveVanityURL/v1/', [
            'key' => $this->apiKey,
            'vanityurl' => $vanityName,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Steam ResolveVanityURL request failed: '.$response->status() . $response->body());
        }

        $result = $response->json('response');

        if (($result['success'] ?? null) !== 1) {
            return null;
        }

        return $result['steamid'];
    }

    /**
     * Fetch owned games for a SteamID64.
     *
     * Returns null for private profiles — Steam signals privacy with an
     * empty response object rather than an error status (V15).
     *
     * @return list<array<string, mixed>>|null
     */
    public function getOwnedGames(string $steamId): ?array
    {
        $response = Http::get(self::BASE_URL.'/IPlayerService/GetOwnedGames/v1/', [
            'key' => $this->apiKey,
            'steamid' => $steamId,
            'include_appinfo' => 1,
            'include_played_free_games' => 1,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Steam GetOwnedGames request failed: '.$response->status());
        }

        $result = $response->json('response');

        if (! is_array($result) || ! array_key_exists('game_count', $result)) {
            return null;
        }

        return $result['games'] ?? [];
    }
}
