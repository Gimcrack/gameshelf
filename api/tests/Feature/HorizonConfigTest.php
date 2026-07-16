<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * V61: prod server is modest — single worker, no auto-scaling headroom.
 */
class HorizonConfigTest extends TestCase
{
    public function test_production_supervisor_is_capped_at_one_process(): void
    {
        $config = require config_path('horizon.php');

        $this->assertSame(1, $config['environments']['production']['supervisor-1']['maxProcesses']);
        $this->assertSame('simple', $config['environments']['production']['supervisor-1']['balance']);
    }

    public function test_default_supervisor_uses_the_redis_connection(): void
    {
        $config = require config_path('horizon.php');

        $this->assertSame('redis', $config['defaults']['supervisor-1']['connection']);
    }
}
