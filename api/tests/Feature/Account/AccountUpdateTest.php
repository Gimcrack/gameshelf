<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountUpdateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'old@example.com',
            'password' => 'correct-horse-battery',
        ]);
        $this->withToken($this->user->createToken('api')->plainTextToken);
    }

    /**
     * V17: no credential change without the current password.
     */
    public function test_rejects_wrong_current_password(): void
    {
        $this->patchJson('/api/user', [
            'email' => 'new@example.com',
            'current_password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors(['current_password']);

        $this->assertSame('old@example.com', $this->user->fresh()->email);
    }

    /**
     * V17: current_password is required, not merely checked when present.
     */
    public function test_requires_current_password(): void
    {
        $this->patchJson('/api/user', [
            'email' => 'new@example.com',
        ])->assertUnprocessable()->assertJsonValidationErrors(['current_password']);
    }

    public function test_updates_email(): void
    {
        $this->patchJson('/api/user', [
            'email' => 'new@example.com',
            'current_password' => 'correct-horse-battery',
        ])->assertOk()->assertJsonPath('email', 'new@example.com');

        $this->assertSame('new@example.com', $this->user->fresh()->email);
    }

    public function test_updates_password(): void
    {
        $this->patchJson('/api/user', [
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
            'current_password' => 'correct-horse-battery',
        ])->assertOk();

        $this->assertTrue(Hash::check('brand-new-password', $this->user->fresh()->password));
    }

    public function test_password_requires_confirmation(): void
    {
        $this->patchJson('/api/user', [
            'password' => 'brand-new-password',
            'password_confirmation' => 'different',
            'current_password' => 'correct-horse-battery',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
    }

    public function test_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->patchJson('/api/user', [
            'email' => 'taken@example.com',
            'current_password' => 'correct-horse-battery',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_response_never_leaks_password_hash(): void
    {
        $response = $this->patchJson('/api/user', [
            'email' => 'new@example.com',
            'current_password' => 'correct-horse-battery',
        ])->assertOk();

        $this->assertArrayNotHasKey('password', $response->json());
    }
}
