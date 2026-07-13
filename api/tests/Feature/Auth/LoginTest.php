<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_in_with_valid_credentials(): void
    {
        User::factory()->create([
            'email' => 'jeremy@example.com',
            'password' => 'secret-password',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'jeremy@example.com',
            'password' => 'secret-password',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'email', 'created_at'],
            ]);
    }

    public function test_rejects_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'jeremy@example.com',
            'password' => 'secret-password',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'jeremy@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_rejects_unknown_email(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'nobody@example.com',
            'password' => 'secret-password',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_token_authenticates_subsequent_requests(): void
    {
        User::factory()->create([
            'email' => 'jeremy@example.com',
            'password' => 'secret-password',
        ]);

        $token = $this->postJson('/api/login', [
            'email' => 'jeremy@example.com',
            'password' => 'secret-password',
        ])->json('token');

        $this->withToken($token)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('email', 'jeremy@example.com');
    }
}
