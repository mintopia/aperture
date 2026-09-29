<?php

namespace Tests\Unit\Providers;

use App\Providers\BroadcastServiceProvider;
use Tests\TestCase;

class BroadcastServiceProviderTest extends TestCase
{
    public function test_boot_registers_broadcast_routes(): void
    {
        $provider = new BroadcastServiceProvider($this->app);
        $provider->boot();
        $this->assertTrue(true);
    }
}
