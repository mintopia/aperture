<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use App\Events\PortStateChanged;
use App\Models\SwitchPort;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use PHPUnit\Framework\TestCase;

class PortStateChangedTest extends TestCase
{
    public function test_can_be_instantiated_with_required_data(): void
    {
        $switchPort = $this->createStub(SwitchPort::class);

        $event = new PortStateChanged($switchPort, 'up', 'down');

        $this->assertSame($switchPort, $event->switchPort);
        $this->assertSame('up', $event->oldStatus);
        $this->assertSame('down', $event->newStatus);
    }

    public function test_old_status_can_be_null(): void
    {
        $switchPort = $this->createStub(SwitchPort::class);

        $event = new PortStateChanged($switchPort, null, 'up');

        $this->assertNull($event->oldStatus);
    }

    public function test_uses_serializes_models_trait(): void
    {
        $traits = class_uses_recursive(PortStateChanged::class);
        $this->assertContains(SerializesModels::class, $traits);
    }

    public function test_uses_dispatchable_trait(): void
    {
        $traits = class_uses_recursive(PortStateChanged::class);
        $this->assertContains(Dispatchable::class, $traits);
    }
}
