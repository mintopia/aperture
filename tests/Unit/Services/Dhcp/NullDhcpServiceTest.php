<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dhcp;

use App\Services\Dhcp\NullDhcpService;
use Tests\TestCase;

class NullDhcpServiceTest extends TestCase
{
    public function test_get_pool_status_returns_zero_values(): void
    {
        $service = new NullDhcpService;

        $status = $service->getPoolStatus();

        $this->assertEquals(0, $status->total);
        $this->assertEquals(0, $status->used);
        $this->assertEquals(0, $status->available);
        $this->assertEquals(0.0, $status->utilisation);
    }

    public function test_get_leases_returns_empty_collection(): void
    {
        $service = new NullDhcpService;

        $leases = $service->getLeases();

        $this->assertCount(0, $leases);
    }

    public function test_get_lease_returns_null(): void
    {
        $service = new NullDhcpService;

        $lease = $service->getLease('10.0.0.1');

        $this->assertNull($lease);
    }

    public function test_get_ranges_returns_empty_collection(): void
    {
        $service = new NullDhcpService;

        $ranges = $service->getRanges();

        $this->assertCount(0, $ranges);
    }
}
