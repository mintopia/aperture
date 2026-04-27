<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\SystemEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SystemEventTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_system_event_can_be_created_with_all_fields(): void
    {
        $event = SystemEvent::create([
            'type' => 'UserConnected',
            'level' => 'info',
            'message' => 'alice connected from 10.0.0.42',
            'data' => ['user_id' => 1, 'ip_address' => '10.0.0.42'],
        ]);

        $this->assertDatabaseHas('system_events', [
            'id' => $event->id,
            'type' => 'UserConnected',
            'level' => 'info',
            'message' => 'alice connected from 10.0.0.42',
        ]);
    }

    public function test_data_is_cast_to_array(): void
    {
        $event = SystemEvent::create([
            'type' => 'DeviceDiscovered',
            'level' => 'info',
            'message' => 'New device discovered',
            'data' => ['mac_address' => 'AA:BB:CC:DD:EE:FF'],
        ]);

        $event->refresh();

        $this->assertIsArray($event->data);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $event->data['mac_address']);
    }

    public function test_data_is_nullable(): void
    {
        $event = SystemEvent::create([
            'type' => 'UserConnected',
            'level' => 'info',
            'message' => 'test',
            'data' => null,
        ]);

        $event->refresh();

        $this->assertNull($event->data);
    }

    public function test_has_no_updated_at_column(): void
    {
        $event = SystemEvent::create([
            'type' => 'UserConnected',
            'level' => 'info',
            'message' => 'test',
        ]);

        $this->assertNull($event->updated_at);
    }

    public function test_factory_creates_valid_model(): void
    {
        $event = SystemEvent::factory()->create();

        $this->assertNotNull($event->id);
        $this->assertNotNull($event->type);
        $this->assertNotNull($event->level);
        $this->assertNotNull($event->message);
    }

    public function test_factory_info_state(): void
    {
        $event = SystemEvent::factory()->info()->create();

        $this->assertSame('info', $event->level);
    }

    public function test_factory_warning_state(): void
    {
        $event = SystemEvent::factory()->warning()->create();

        $this->assertSame('warning', $event->level);
    }

    public function test_factory_critical_state(): void
    {
        $event = SystemEvent::factory()->critical()->create();

        $this->assertSame('critical', $event->level);
    }
}
