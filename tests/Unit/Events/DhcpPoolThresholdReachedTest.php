<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use App\Events\DhcpPoolThresholdReached;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use PHPUnit\Framework\TestCase;

class DhcpPoolThresholdReachedTest extends TestCase
{
    public function test_can_be_instantiated_with_required_data(): void
    {
        $event = new DhcpPoolThresholdReached('lan-pool', 85.5, 80.0);

        $this->assertSame('lan-pool', $event->pool);
        $this->assertSame(85.5, $event->usage);
        $this->assertSame(80.0, $event->threshold);
    }

    public function test_usage_at_threshold_boundary(): void
    {
        $event = new DhcpPoolThresholdReached('guest-pool', 90.0, 90.0);

        $this->assertSame(90.0, $event->usage);
        $this->assertSame(90.0, $event->threshold);
    }

    public function test_uses_dispatchable_trait(): void
    {
        $traits = class_uses_recursive(DhcpPoolThresholdReached::class);
        $this->assertContains(Dispatchable::class, $traits);
    }

    public function test_does_not_use_serializes_models_trait(): void
    {
        // DhcpPoolThresholdReached uses only scalar values, no models to serialize
        $traits = class_uses_recursive(DhcpPoolThresholdReached::class);
        $this->assertNotContains(SerializesModels::class, $traits);
    }
}
