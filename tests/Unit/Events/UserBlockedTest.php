<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use App\Events\UserBlocked;
use App\Models\IpAddress;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use PHPUnit\Framework\TestCase;

class UserBlockedTest extends TestCase
{
    public function test_can_be_instantiated_with_required_data(): void
    {
        $user = $this->createStub(User::class);
        $ipAddress = $this->createStub(IpAddress::class);
        $reason = 'Violation of terms of service';

        $event = new UserBlocked($user, $ipAddress, $reason);

        $this->assertSame($user, $event->user);
        $this->assertSame($ipAddress, $event->ipAddress);
        $this->assertSame($reason, $event->reason);
    }

    public function test_uses_serializes_models_trait(): void
    {
        $traits = class_uses_recursive(UserBlocked::class);
        $this->assertContains(SerializesModels::class, $traits);
    }

    public function test_uses_dispatchable_trait(): void
    {
        $traits = class_uses_recursive(UserBlocked::class);
        $this->assertContains(Dispatchable::class, $traits);
    }
}
