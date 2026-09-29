<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Http\Middleware\TrustHosts;
use Illuminate\Contracts\Http\Kernel;
use Tests\TestCase;

class TrustHostsKernelTest extends TestCase
{
    public function test_trust_hosts_is_in_global_middleware_stack(): void
    {
        $kernel = $this->app->make(Kernel::class);

        $this->assertContains(TrustHosts::class, $kernel->getGlobalMiddleware());
    }
}
