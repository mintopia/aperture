<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use App\Events\DeviceDiscovered;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\SwitchPort;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use PHPUnit\Framework\TestCase;

class DeviceDiscoveredTest extends TestCase
{
    public function test_can_be_instantiated_with_required_data(): void
    {
        $macAddress = $this->createStub(MacAddress::class);
        $ipAddress = $this->createStub(IpAddress::class);
        $switchPort = $this->createStub(SwitchPort::class);

        $event = new DeviceDiscovered($macAddress, $ipAddress, $switchPort);

        $this->assertSame($macAddress, $event->macAddress);
        $this->assertSame($ipAddress, $event->ipAddress);
        $this->assertSame($switchPort, $event->switchPort);
    }

    public function test_ip_address_is_nullable(): void
    {
        $macAddress = $this->createStub(MacAddress::class);
        $switchPort = $this->createStub(SwitchPort::class);

        $event = new DeviceDiscovered($macAddress, null, $switchPort);

        $this->assertNull($event->ipAddress);
    }

    public function test_switch_port_is_nullable(): void
    {
        $macAddress = $this->createStub(MacAddress::class);
        $ipAddress = $this->createStub(IpAddress::class);

        $event = new DeviceDiscovered($macAddress, $ipAddress, null);

        $this->assertNull($event->switchPort);
    }

    public function test_uses_serializes_models_trait(): void
    {
        $traits = class_uses_recursive(DeviceDiscovered::class);
        $this->assertContains(SerializesModels::class, $traits);
    }

    public function test_uses_dispatchable_trait(): void
    {
        $traits = class_uses_recursive(DeviceDiscovered::class);
        $this->assertContains(Dispatchable::class, $traits);
    }
}
