<?php

namespace Tests\Unit\Services\Null;

use App\Services\Null\NullPortBandwidth;
use App\Services\ValueObjects\PortTimeSeries;
use PHPUnit\Framework\TestCase;

class NullPortBandwidthTest extends TestCase
{
    private NullPortBandwidth $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new NullPortBandwidth;
    }

    public function test_get_port_bandwidth_returns_empty_series(): void
    {
        $result = $this->provider->getPortBandwidth('switch1', 'GigabitEthernet0/1', 0.0, 1000.0);
        $this->assertInstanceOf(PortTimeSeries::class, $result);
        $this->assertSame([], $result->in);
        $this->assertSame([], $result->out);
    }

    public function test_is_available_returns_false(): void
    {
        $this->assertFalse($this->provider->isAvailable());
    }
}
