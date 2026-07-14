<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->withToken($this->user->createToken('api')->plainTextToken);
    }

    /**
     * V18: plaintext appears exactly once — in the creation response.
     */
    public function test_create_returns_plaintext_once_and_stores_hash(): void
    {
        $response = $this->postJson('/api/tokens', ['name' => 'CI script'])
            ->assertCreated()
            ->assertJsonPath('name', 'CI script');

        $plaintext = $response->json('token');
        $this->assertNotNull($plaintext);

        // Stored value is a hash, never the plaintext.
        $stored = DB::table('personal_access_tokens')->where('name', 'CI script')->first();
        $this->assertNotNull($stored);
        $secret = explode('|', $plaintext, 2)[1] ?? $plaintext;
        $this->assertNotSame($secret, $stored->token);
        $this->assertSame(hash('sha256', $secret), $stored->token);
    }

    /**
     * V18: listing never exposes token material.
     */
    public function test_list_excludes_token_material(): void
    {
        $this->postJson('/api/tokens', ['name' => 'CI script'])->assertCreated();

        $tokens = $this->getJson('/api/tokens')->assertOk()->json();

        $this->assertNotEmpty($tokens);
        foreach ($tokens as $token) {
            $this->assertArrayNotHasKey('token', $token);
            $this->assertArrayHasKey('name', $token);
            $this->assertArrayHasKey('last_used_at', $token);
            $this->assertArrayHasKey('current', $token);
        }
    }

    public function test_current_session_token_flagged(): void
    {
        $tokens = $this->getJson('/api/tokens')->assertOk()->json();

        $this->assertCount(1, $tokens);
        $this->assertTrue($tokens[0]['current']);
    }

    public function test_revoked_token_stops_authenticating(): void
    {
        $plaintext = $this->postJson('/api/tokens', ['name' => 'Doomed'])->json('token');
        $id = collect($this->getJson('/api/tokens')->json())->firstWhere('name', 'Doomed')['id'];

        $this->deleteJson("/api/tokens/{$id}")->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->withToken($plaintext)->getJson('/api/library')->assertUnauthorized();
    }

    public function test_cannot_revoke_other_users_token(): void
    {
        $other = User::factory()->create();
        $otherTokenId = $other->createToken('theirs')->accessToken->id;

        $this->deleteJson("/api/tokens/{$otherTokenId}")->assertNotFound();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherTokenId]);
    }

    public function test_token_name_required(): void
    {
        $this->postJson('/api/tokens', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }
}
