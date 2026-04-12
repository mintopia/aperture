<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use App\Events\IpAllowed;
use Illuminate\Broadcasting\PrivateChannel;
use PHPUnit\Framework\TestCase;

class IpAllowedTest extends TestCase
{
    public function test_can_be_instantiated(): void
    {
        $event = new IpAllowed;
        $this->assertInstanceOf(IpAllowed::class, $event);
    }

    public function test_broadcast_on_returns_private_channel(): void
    {
        $event = new IpAllowed;
        $channels = $event->broadcastOn();
        $this->assertIsArray($channels);
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
    }
}
