<?php

namespace Tests\Feature\FamilyMembers;

use App\Models\Game;
use App\Models\OwnedGame;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FamilyMemberControllerTest extends TestCase
{
    use RefreshDatabase;

    private const MEMBER_STEAM_ID = '76561197960287999';

    private function authed(): User
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('t')->plainTextToken);

        return $user;
    }

    private function fakePlayerSummary(): void
    {
        Http::fake([
            'api.steampowered.com/ISteamUser/GetPlayerSummaries/*' => Http::response([
                'response' => [
                    'players' => [[
                        'steamid' => self::MEMBER_STEAM_ID,
                        'personaname' => 'FamilyMember',
                        'avatarfull' => 'https://avatars.steamstatic.com/family_full.jpg',
                    ]],
                ],
            ]),
        ]);
    }

    public function test_adds_family_member_creates_synthetic_connection_and_queues_sync(): void
    {
        Queue::fake();
        $this->fakePlayerSummary();
        $this->authed();

        $response = $this->postJson('/api/family-members', ['steam_id' => self::MEMBER_STEAM_ID]);

        $response->assertCreated()
            ->assertJsonPath('steam_id', self::MEMBER_STEAM_ID)
            ->assertJsonPath('persona_name', 'FamilyMember')
            ->assertJsonPath('status', 'pending');

        $this->assertDatabaseHas('platform_connections', [
            'platform' => 'steam_family',
            'external_account_id' => self::MEMBER_STEAM_ID,
        ]);
        $this->assertDatabaseHas('family_members', [
            'steam_id' => self::MEMBER_STEAM_ID,
            'persona_name' => 'FamilyMember',
        ]);
    }

    public function test_duplicate_steam_id_rejected(): void
    {
        Queue::fake();
        $this->fakePlayerSummary();
        $user = $this->authed();
        $connection = PlatformConnection::factory()->create([
            'user_id' => $user->id,
            'platform' => 'steam_family',
            'external_account_id' => self::MEMBER_STEAM_ID,
        ]);
        $user->familyMembers()->create([
            'steam_id' => self::MEMBER_STEAM_ID,
            'persona_name' => 'FamilyMember',
            'avatar_url' => 'https://avatars.steamstatic.com/family_full.jpg',
            'platform_connection_id' => $connection->id,
        ]);

        $this->postJson('/api/family-members', ['steam_id' => self::MEMBER_STEAM_ID])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['steam_id']);
    }

    public function test_unknown_steam_id_is_not_found(): void
    {
        Http::fake([
            'api.steampowered.com/ISteamUser/GetPlayerSummaries/*' => Http::response([
                'response' => ['players' => []],
            ]),
        ]);
        $this->authed();

        $this->postJson('/api/family-members', ['steam_id' => self::MEMBER_STEAM_ID])
            ->assertNotFound();

        $this->assertDatabaseCount('family_members', 0);
        $this->assertDatabaseCount('platform_connections', 0);
    }

    public function test_lists_family_members_with_status(): void
    {
        $user = $this->authed();
        $connection = PlatformConnection::factory()->create([
            'user_id' => $user->id,
            'platform' => 'steam_family',
            'external_account_id' => self::MEMBER_STEAM_ID,
            'status' => 'ok',
        ]);
        $user->familyMembers()->create([
            'steam_id' => self::MEMBER_STEAM_ID,
            'persona_name' => 'FamilyMember',
            'avatar_url' => 'https://avatars.steamstatic.com/family_full.jpg',
            'platform_connection_id' => $connection->id,
        ]);

        $this->getJson('/api/family-members')
            ->assertOk()
            ->assertJsonPath('0.steam_id', self::MEMBER_STEAM_ID)
            ->assertJsonPath('0.status', 'ok');
    }

    /**
     * Hard-delete cascades: connection + family_members row + shared
     * owned_games rows sourced from it (⊥ V13 soft-keep — no real "your
     * data" to preserve).
     */
    public function test_removing_family_member_cascades_connection_and_shared_rows(): void
    {
        $user = $this->authed();
        $connection = PlatformConnection::factory()->create([
            'user_id' => $user->id,
            'platform' => 'steam_family',
            'external_account_id' => self::MEMBER_STEAM_ID,
            'status' => 'ok',
        ]);
        $member = $user->familyMembers()->create([
            'steam_id' => self::MEMBER_STEAM_ID,
            'persona_name' => 'FamilyMember',
            'avatar_url' => 'https://avatars.steamstatic.com/family_full.jpg',
            'platform_connection_id' => $connection->id,
        ]);
        $game = Game::create(['title' => 'Portal 2']);
        OwnedGame::create([
            'user_id' => $user->id,
            'platform_connection_id' => $connection->id,
            'game_id' => $game->id,
            'platform_game_id' => '620',
            'added_at' => now(),
            'shared' => true,
        ]);

        $this->deleteJson("/api/family-members/{$member->id}")->assertOk();

        $this->assertDatabaseMissing('family_members', ['id' => $member->id]);
        $this->assertDatabaseMissing('platform_connections', ['id' => $connection->id]);
        $this->assertDatabaseMissing('owned_games', ['platform_connection_id' => $connection->id]);
    }

    public function test_cannot_remove_another_users_family_member(): void
    {
        $owner = User::factory()->create();
        $connection = PlatformConnection::factory()->create([
            'user_id' => $owner->id,
            'platform' => 'steam_family',
        ]);
        $member = $owner->familyMembers()->create([
            'steam_id' => self::MEMBER_STEAM_ID,
            'persona_name' => 'FamilyMember',
            'avatar_url' => 'https://avatars.steamstatic.com/family_full.jpg',
            'platform_connection_id' => $connection->id,
        ]);
        $this->authed();

        $this->deleteJson("/api/family-members/{$member->id}")->assertNotFound();

        $this->assertDatabaseHas('family_members', ['id' => $member->id]);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/family-members')->assertUnauthorized();
        $this->postJson('/api/family-members', ['steam_id' => self::MEMBER_STEAM_ID])->assertUnauthorized();
    }
}
