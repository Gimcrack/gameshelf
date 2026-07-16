<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * V61: Horizon dashboard access — no session-based web login exists in this
 * Sanctum-only app, so HorizonBasicAuth gates it instead of a Gate check.
 */
class HorizonAccessTest extends TestCase
{
    public function test_denies_access_when_credentials_not_configured(): void
    {
        Config::set('horizon.basic_auth.username', null);
        Config::set('horizon.basic_auth.password', null);

        $this->get('/horizon')->assertForbidden();
    }

    public function test_prompts_basic_auth_when_credentials_configured_but_missing(): void
    {
        Config::set('horizon.basic_auth.username', 'admin');
        Config::set('horizon.basic_auth.password', 'secret');

        $response = $this->get('/horizon');

        $response->assertUnauthorized();
        $this->assertSame('Basic realm="Horizon"', $response->headers->get('WWW-Authenticate'));
    }

    public function test_rejects_wrong_credentials(): void
    {
        Config::set('horizon.basic_auth.username', 'admin');
        Config::set('horizon.basic_auth.password', 'secret');

        $this->withHeaders(['Authorization' => 'Basic '.base64_encode('admin:wrong')])
            ->get('/horizon')
            ->assertUnauthorized();
    }

    public function test_allows_correct_credentials(): void
    {
        Config::set('horizon.basic_auth.username', 'admin');
        Config::set('horizon.basic_auth.password', 'secret');

        $response = $this->withHeaders(['Authorization' => 'Basic '.base64_encode('admin:secret')])
            ->get('/horizon');

        $response->assertOk();
    }
}
