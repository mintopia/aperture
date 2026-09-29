<?php

namespace Tests\Unit\Services\Null;

use App\Services\Null\NullIpBandwidth;
use App\Services\ValueObjects\IpBandwidthResult;
use PHPUnit\Framework\TestCase;

class NullIpBandwidthTest extends TestCase
{
    private NullIpBandwidth $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new NullIpBandwidth;
    }

    public function test_get_ip_bandwidth_returns_empty_result(): void
    {
        $result = $this->provider->getIpBandwidth('10.0.0.1');
        $this->assertInstanceOf(IpBandwidthResult::class, $result);
        $this->assertSame(0, $result->received);
        $this->assertSame(0, $result->sent);
        $this->assertSame([], $result->timestamps);
        $this->assertSame([], $result->download);
        $this->assertSame([], $result->upload);
    }

    public function test_get_ip_bandwidth_accepts_array(): void
    {
        $result = $this->provider->getIpBandwidth(['10.0.0.1', '10.0.0.2']);
        $this->assertInstanceOf(IpBandwidthResult::class, $result);
    }

    public function test_get_total_bandwidth_returns_empty_result(): void
    {
        $result = $this->provider->getTotalBandwidth();
        $this->assertInstanceOf(IpBandwidthResult::class, $result);
        $this->assertSame(0, $result->received);
    }

    public function test_get_top_talkers_returns_empty_collection(): void
    {
        $result = $this->provider->getTopTalkers();
        $this->assertCount(0, $result);
    }

    public function test_get_top_talkers_accepts_range(): void
    {
        $result = $this->provider->getTopTalkers(limit: 5, range: '5m');
        $this->assertCount(0, $result);
    }
}
