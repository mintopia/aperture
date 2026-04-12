<?php

namespace Tests\Unit\Http;

use App\Http\Kernel;
use ReflectionClass;
use Tests\TestCase;

class KernelTest extends TestCase
{
    public function test_http_kernel_has_middleware_groups(): void
    {
        $kernel = $this->app->make(Kernel::class);

        $reflection = new ReflectionClass($kernel);
        $prop = $reflection->getProperty('middlewareGroups');
        $groups = $prop->getValue($kernel);

        $this->assertArrayHasKey('web', $groups);
        $this->assertArrayHasKey('api', $groups);
    }

    public function test_http_kernel_has_middleware_aliases(): void
    {
        $kernel = $this->app->make(Kernel::class);

        $reflection = new ReflectionClass($kernel);
        $prop = $reflection->getProperty('middlewareAliases');
        $aliases = $prop->getValue($kernel);

        $this->assertArrayHasKey('auth', $aliases);
        $this->assertArrayHasKey('guest', $aliases);
        $this->assertArrayHasKey('throttle', $aliases);
    }
}
