<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use App\Events\BandwidthAnomalyDetected;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use PHPUnit\Framework\TestCase;

class BandwidthAnomalyDetectedTest extends TestCase
{
    public function test_can_be_instantiated_with_required_data(): void
    {
        $event = new BandwidthAnomalyDetected(
            ipAddress: '10.0.0.50',
            userName: 'John Doe',
            userId: 42,
            shortTermAvg: 150000.0,
            longTermAvg: 30000.0,
            ratio: 5.0,
            threshold: 3.0,
        );

        $this->assertSame('10.0.0.50', $event->ipAddress);
        $this->assertSame('John Doe', $event->userName);
        $this->assertSame(42, $event->userId);
        $this->assertSame(150000.0, $event->shortTermAvg);
        $this->assertSame(30000.0, $event->longTermAvg);
        $this->assertSame(5.0, $event->ratio);
        $this->assertSame(3.0, $event->threshold);
    }

    public function test_can_be_instantiated_with_null_user(): void
    {
        $event = new BandwidthAnomalyDetected(
            ipAddress: '10.0.0.99',
            userName: null,
            userId: null,
            shortTermAvg: 100000.0,
            longTermAvg: 20000.0,
            ratio: 5.0,
            threshold: 3.0,
        );

        $this->assertSame('10.0.0.99', $event->ipAddress);
        $this->assertNull($event->userName);
        $this->assertNull($event->userId);
    }

    public function test_implements_should_broadcast(): void
    {
        $this->assertTrue(
            is_subclass_of(BandwidthAnomalyDetected::class, ShouldBroadcast::class)
        );
    }

    public function test_uses_dispatchable_trait(): void
    {
        $traits = class_uses_recursive(BandwidthAnomalyDetected::class);
        $this->assertContains(Dispatchable::class, $traits);
    }

    public function test_uses_interacts_with_sockets_trait(): void
    {
        $traits = class_uses_recursive(BandwidthAnomalyDetected::class);
        $this->assertContains(InteractsWithSockets::class, $traits);
    }

    public function test_ratio_at_threshold_boundary(): void
    {
        $event = new BandwidthAnomalyDetected(
            ipAddress: '10.0.0.1',
            userName: 'Test',
            userId: 1,
            shortTermAvg: 90000.0,
            longTermAvg: 30000.0,
            ratio: 3.0,
            threshold: 3.0,
        );

        $this->assertSame(3.0, $event->ratio);
        $this->assertSame(3.0, $event->threshold);
    }
}
