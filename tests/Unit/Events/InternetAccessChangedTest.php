<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use App\Events\InternetAccessChanged;
use App\Models\IpAddress;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use PHPUnit\Framework\TestCase;

class InternetAccessChangedTest extends TestCase
{
    public function test_can_be_instantiated_with_enabled(): void
    {
        $ipAddress = $this->createStub(IpAddress::class);
        $changedBy = $this->createStub(User::class);

        $event = new InternetAccessChanged($ipAddress, true, $changedBy);

        $this->assertSame($ipAddress, $event->ipAddress);
        $this->assertTrue($event->enabled);
        $this->assertSame($changedBy, $event->changedBy);
    }

    public function test_can_be_instantiated_with_disabled(): void
    {
        $ipAddress = $this->createStub(IpAddress::class);
        $changedBy = $this->createStub(User::class);

        $event = new InternetAccessChanged($ipAddress, false, $changedBy);

        $this->assertFalse($event->enabled);
    }

    public function test_changed_by_is_nullable(): void
    {
        $ipAddress = $this->createStub(IpAddress::class);

        $event = new InternetAccessChanged($ipAddress, true, null);

        $this->assertNull($event->changedBy);
    }

    public function test_uses_serializes_models_trait(): void
    {
        $traits = class_uses_recursive(InternetAccessChanged::class);
        $this->assertContains(SerializesModels::class, $traits);
    }

    public function test_uses_dispatchable_trait(): void
    {
        $traits = class_uses_recursive(InternetAccessChanged::class);
        $this->assertContains(Dispatchable::class, $traits);
    }
}
