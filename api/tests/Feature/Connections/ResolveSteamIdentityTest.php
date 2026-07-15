<?php

namespace Tests\Feature\Connections;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResolveSteamIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function authed(): User
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('t')->plainTextToken);

        return $user;
    }

    private function fakePlayerSummary(string $steamId = '76561197960287930'): void
    {
        Http::fake([
            'api.steampowered.com/ISteamUser/GetPlayerSummaries/*' => Http::response([
                'response' => [
                    'players' => [[
                        'steamid' => $steamId,
                        'personaname' => 'GabeN',
                        'avatarfull' => 'https://avatars.steamstatic.com/gaben_full.jpg',
                    ]],
                ],
            ]),
        ]);
    }

    /**
     * V25: identity lookup is a pure preview — never writes a connection.
     */
    public function test_resolve_by_steam_id_returns_identity_without_creating_connection(): void
    {
        $this->fakePlayerSummary();
        $this->authed();

        $this->getJson('/api/connections/steam/resolve?steam_id=76561197960287930')
            ->assertOk()
            ->assertExactJson([
                'steam_id' => '76561197960287930',
                'persona_name' => 'GabeN',
                'avatar_url' => 'https://avatars.steamstatic.com/gaben_full.jpg',
            ]);

        $this->assertDatabaseCount('platform_connections', 0);
    }

    public function test_resolve_by_vanity_url_returns_identity(): void
    {
        Http::fake([
            'api.steampowered.com/ISteamUser/ResolveVanityURL/*' => Http::response([
                'response' => ['success' => 1, 'steamid' => '76561197960287930'],
            ]),
            'api.steampowered.com/ISteamUser/GetPlayerSummaries/*' => Http::response([
                'response' => [
                    'players' => [[
                        'steamid' => '76561197960287930',
                        'personaname' => 'GabeN',
                        'avatarfull' => 'https://avatars.steamstatic.com/gaben_full.jpg',
                    ]],
                ],
            ]),
        ]);
        $this->authed();

        $this->getJson('/api/connections/steam/resolve?vanity_url=gabelogannewell')
            ->assertOk()
            ->assertJsonPath('persona_name', 'GabeN');

        $this->assertDatabaseCount('platform_connections', 0);
    }

    public function test_unknown_vanity_url_is_unprocessable(): void
    {
        Http::fake([
            'api.steampowered.com/ISteamUser/ResolveVanityURL/*' => Http::response([
                'response' => ['success' => 42],
            ]),
        ]);
        $this->authed();

        $this->getJson('/api/connections/steam/resolve?vanity_url=nobody')
            ->assertUnprocessable();
    }

    /**
     * Resolved steamid Steam won't return a summary for — edge case, but
     * still shouldn't ever create a connection.
     */
    public function test_no_player_summary_is_not_found(): void
    {
        Http::fake([
            'api.steampowered.com/ISteamUser/GetPlayerSummaries/*' => Http::response([
                'response' => ['players' => []],
            ]),
        ]);
        $this->authed();

        $this->getJson('/api/connections/steam/resolve?steam_id=76561197960287930')
            ->assertNotFound();

        $this->assertDatabaseCount('platform_connections', 0);
    }

    public function test_missing_both_params_is_unprocessable(): void
    {
        $this->authed();

        $this->getJson('/api/connections/steam/resolve')->assertUnprocessable();
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/connections/steam/resolve?steam_id=76561197960287930')
            ->assertUnauthorized();
    }
}
