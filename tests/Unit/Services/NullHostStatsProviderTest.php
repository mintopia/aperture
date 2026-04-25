<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Null\NullHostStatsProvider;
use Tests\TestCase;

class NullHostStatsProviderTest extends TestCase
{
    public function test_get_host_bytes_returns_null(): void
    {
        $provider = new NullHostStatsProvider;

        $this->assertNull($provider->getHostBytes('10.0.0.1'));
    }
}
