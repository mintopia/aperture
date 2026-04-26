<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use App\Events\SwitchSyncCompleted;
use App\Models\SwitchConfig;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use PHPUnit\Framework\TestCase;

class SwitchSyncCompletedTest extends TestCase
{
    public function test_can_be_instantiated_with_required_data(): void
    {
        $switchConfig = $this->createStub(SwitchConfig::class);

        $event = new SwitchSyncCompleted($switchConfig, 24, []);

        $this->assertSame($switchConfig, $event->switchConfig);
        $this->assertSame(24, $event->portsUpdated);
        $this->assertSame([], $event->errors);
    }

    public function test_can_carry_errors(): void
    {
        $switchConfig = $this->createStub(SwitchConfig::class);
        $errors = ['Port 1 timeout', 'Port 5 unreachable'];

        $event = new SwitchSyncCompleted($switchConfig, 22, $errors);

        $this->assertSame($errors, $event->errors);
    }

    public function test_uses_serializes_models_trait(): void
    {
        $traits = class_uses_recursive(SwitchSyncCompleted::class);
        $this->assertContains(SerializesModels::class, $traits);
    }

    public function test_uses_dispatchable_trait(): void
    {
        $traits = class_uses_recursive(SwitchSyncCompleted::class);
        $this->assertContains(Dispatchable::class, $traits);
    }
}
