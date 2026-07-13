<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * V3: every API response is JSON — never HTML, never a redirect.
 */
class JsonResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_gets_json_401_not_redirect(): void
    {
        // No Accept header — simulates a non-JSON-aware client.
        $response = $this->get('/api/user');

        $response->assertUnauthorized();
        $response->assertHeader('Content-Type', 'application/json');
    }

    public function test_validation_failure_gets_json_422_not_redirect(): void
    {
        // Plain form POST without Accept: application/json.
        $response = $this->post('/api/register', ['email' => 'bad']);

        $response->assertUnprocessable();
        $response->assertHeader('Content-Type', 'application/json');
    }

    public function test_not_found_on_api_route_is_json(): void
    {
        $response = $this->get('/api/nonexistent');

        $response->assertNotFound();
        $response->assertHeader('Content-Type', 'application/json');
    }

    public function test_authenticated_user_endpoint_returns_json(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->get('/api/user');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $this->assertArrayNotHasKey('password', $response->json());
    }
}
