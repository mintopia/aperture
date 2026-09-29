<?php

namespace Tests\Unit\Providers;

use App\Providers\BroadcastServiceProvider;
use Tests\TestCase;

class BroadcastServiceProviderTest extends TestCase
{
    public function test_boot_registers_broadcast_routes(): void
    {
        (new BroadcastServiceProvider($this->app))->boot();

        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'broadcasting/auth');

        $this->assertNotNull($route);
        $this->assertContains('auth', $route->gatherMiddleware());
    }
}
