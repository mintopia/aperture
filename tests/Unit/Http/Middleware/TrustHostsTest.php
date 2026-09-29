<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\TrustHosts;
use Tests\TestCase;

class TrustHostsTest extends TestCase
{
    public function test_hosts_returns_array_with_application_subdomains(): void
    {
        $middleware = new TrustHosts($this->app);
        $hosts = $middleware->hosts();
        $this->assertIsArray($hosts);
        $this->assertNotEmpty($hosts);
    }
}
