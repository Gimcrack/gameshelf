<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_registers_user_and_returns_token(): void
    {
        $response = $this->postJson('/api/register', [
            'email' => 'jeremy@example.com',
            'password' => 'secret-password',
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'email', 'created_at'],
            ]);

        $this->assertDatabaseHas('users', ['email' => 'jeremy@example.com']);
    }

    public function test_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'jeremy@example.com']);

        $response = $this->postJson('/api/register', [
            'email' => 'jeremy@example.com',
            'password' => 'secret-password',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_rejects_weak_password(): void
    {
        $response = $this->postJson('/api/register', [
            'email' => 'jeremy@example.com',
            'password' => 'short',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_rejects_invalid_email(): void
    {
        $response = $this->postJson('/api/register', [
            'email' => 'not-an-email',
            'password' => 'secret-password',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_does_not_leak_password_in_response(): void
    {
        $response = $this->postJson('/api/register', [
            'email' => 'jeremy@example.com',
            'password' => 'secret-password',
        ]);

        $response->assertCreated();
        $this->assertArrayNotHasKey('password', $response->json('user'));
    }
}
