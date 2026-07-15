<?php

namespace App\Services\Steam;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SteamClient
{
    private const BASE_URL = 'https://api.steampowered.com';

    private const STORE_URL = 'https://store.steampowered.com';

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
            // V23: omit include_played_free_games — Steam's own docs note
            // free games are excluded by default "since technically anyone
            // can own them"; setting this flooded libraries with unwanted
            // F2P titles the user never chose to add.
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

    /**
     * Public identity for a SteamID64 — name/avatar are visible even when
     * the account's game library is private (V15 is a library-privacy
     * concern, not an identity one). Null when Steam has no such account.
     *
     * @return array{steam_id: string, persona_name: string, avatar_url: string}|null
     */
    public function playerSummary(string $steamId): ?array
    {
        $response = Http::get(self::BASE_URL.'/ISteamUser/GetPlayerSummaries/v2/', [
            'key' => $this->apiKey,
            'steamids' => $steamId,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Steam GetPlayerSummaries request failed: '.$response->status());
        }

        $player = $response->json('response.players.0');

        if ($player === null) {
            return null;
        }

        return [
            'steam_id' => (string) $player['steamid'],
            'persona_name' => (string) $player['personaname'],
            'avatar_url' => (string) $player['avatarfull'],
        ];
    }

    /**
     * Wishlist appids for a SteamID64, or null for private wishlists —
     * Steam signals privacy with an empty response object (V15 pattern).
     * READ ONLY: Steam has no public wishlist write API (V22).
     *
     * @return list<int>|null
     */
    public function getWishlist(string $steamId): ?array
    {
        $response = Http::get(self::BASE_URL.'/IWishlistService/GetWishlist/v1/', [
            'key' => $this->apiKey,
            'steamid' => $steamId,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Steam GetWishlist request failed: '.$response->status());
        }

        $result = $response->json('response');

        if (! is_array($result) || ! array_key_exists('items', $result)) {
            return null;
        }

        return array_map(fn (array $item) => (int) $item['appid'], $result['items']);
    }

    /**
     * Store title for an appid, cached forever — names don't churn and the
     * unauthenticated store endpoint rate-limits aggressively.
     */
    public function appName(int $appId): ?string
    {
        return Cache::rememberForever("steam-app-name:{$appId}", function () use ($appId) {
            $response = Http::get(self::STORE_URL.'/api/appdetails', ['appids' => $appId]);

            if ($response->failed()) {
                throw new RuntimeException('Steam appdetails request failed: '.$response->status());
            }

            $entry = $response->json((string) $appId);

            return ($entry['success'] ?? false) ? ($entry['data']['name'] ?? null) : null;
        });
    }
}
