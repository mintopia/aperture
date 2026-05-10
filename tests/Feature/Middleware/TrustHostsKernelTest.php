<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Http\Kernel;
use App\Http\Middleware\TrustHosts;
use ReflectionClass;
use Tests\TestCase;

class TrustHostsKernelTest extends TestCase
{
    public function test_trust_hosts_is_in_global_middleware_stack(): void
    {
        $kernel = $this->app->make(Kernel::class);

        $reflection = new ReflectionClass($kernel);
        $property = $reflection->getProperty('middleware');
        $property->setAccessible(true);

        $middleware = $property->getValue($kernel);

        $this->assertContains(TrustHosts::class, $middleware);
    }
}
