<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use App\Events\RateLimitChanged;
use App\Models\IpAddress;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use PHPUnit\Framework\TestCase;

class RateLimitChangedTest extends TestCase
{
    public function test_can_be_instantiated_with_required_data(): void
    {
        $ipAddress = $this->createStub(IpAddress::class);
        $changedBy = $this->createStub(User::class);

        $event = new RateLimitChanged($ipAddress, 1000, 5000, $changedBy);

        $this->assertSame($ipAddress, $event->ipAddress);
        $this->assertSame(1000, $event->oldLimit);
        $this->assertSame(5000, $event->newLimit);
        $this->assertSame($changedBy, $event->changedBy);
    }

    public function test_old_limit_can_be_null(): void
    {
        $ipAddress = $this->createStub(IpAddress::class);
        $changedBy = $this->createStub(User::class);

        $event = new RateLimitChanged($ipAddress, null, 5000, $changedBy);

        $this->assertNull($event->oldLimit);
    }

    public function test_changed_by_is_nullable(): void
    {
        $ipAddress = $this->createStub(IpAddress::class);

        $event = new RateLimitChanged($ipAddress, 1000, 5000, null);

        $this->assertNull($event->changedBy);
    }

    public function test_uses_serializes_models_trait(): void
    {
        $traits = class_uses_recursive(RateLimitChanged::class);
        $this->assertContains(SerializesModels::class, $traits);
    }

    public function test_uses_dispatchable_trait(): void
    {
        $traits = class_uses_recursive(RateLimitChanged::class);
        $this->assertContains(Dispatchable::class, $traits);
    }
}
