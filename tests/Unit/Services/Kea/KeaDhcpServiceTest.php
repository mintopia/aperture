<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kea;

use App\Services\Interfaces\DhcpInterface;
use App\Services\Kea\KeaDhcpService;
use App\Services\ValueObjects\DhcpPoolStatus;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class KeaDhcpServiceTest extends TestCase
{
    private KeaDhcpService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new KeaDhcpService;
    }

    public function test_implements_dhcp_interface(): void
    {
        $this->assertInstanceOf(DhcpInterface::class, $this->service);
    }

    public function test_get_pool_status_returns_zeroed_status(): void
    {
        $status = $this->service->getPoolStatus();

        $this->assertInstanceOf(DhcpPoolStatus::class, $status);
        $this->assertSame(0, $status->total);
        $this->assertSame(0, $status->used);
        $this->assertSame(0, $status->available);
        $this->assertSame(0.0, $status->utilisation);
    }

    public function test_get_leases_returns_empty_collection(): void
    {
        $leases = $this->service->getLeases();

        $this->assertInstanceOf(Collection::class, $leases);
        $this->assertTrue($leases->isEmpty());
    }

    public function test_get_lease_returns_null(): void
    {
        $this->assertNull($this->service->getLease('10.0.0.1'));
    }

    public function test_get_ranges_returns_empty_collection(): void
    {
        $ranges = $this->service->getRanges();

        $this->assertInstanceOf(Collection::class, $ranges);
        $this->assertTrue($ranges->isEmpty());
    }
}
