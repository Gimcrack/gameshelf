<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_revokes_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/logout')
            ->assertNoContent();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized();
    }
}
