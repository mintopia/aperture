<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\SwitchConfig;
use App\Models\SwitchSyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SwitchSyncRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_valid_instance(): void
    {
        $run = SwitchSyncRun::factory()->create();

        $this->assertInstanceOf(SwitchSyncRun::class, $run);
        $this->assertNotNull($run->id);
        $this->assertSame('pending', $run->status);
    }

    public function test_fillable_fields(): void
    {
        $switchConfig = SwitchConfig::factory()->create();

        $run = SwitchSyncRun::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'status' => 'completed',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
            'error' => null,
            'ports_created' => 12,
            'ports_updated' => 36,
            'macs_created' => 45,
            'macs_updated' => 22,
        ]);

        $this->assertDatabaseHas('switch_sync_runs', [
            'id' => $run->id,
            'switch_config_id' => $switchConfig->id,
            'status' => 'completed',
            'ports_created' => 12,
            'ports_updated' => 36,
            'macs_created' => 45,
            'macs_updated' => 22,
        ]);
    }

    public function test_started_at_cast_to_datetime(): void
    {
        $run = SwitchSyncRun::factory()->create([
            'started_at' => '2025-06-15 12:00:00',
        ]);

        $run->refresh();

        $this->assertInstanceOf(Carbon::class, $run->started_at);
    }

    public function test_finished_at_cast_to_datetime(): void
    {
        $run = SwitchSyncRun::factory()->create([
            'finished_at' => '2025-06-15 12:30:00',
        ]);

        $run->refresh();

        $this->assertInstanceOf(Carbon::class, $run->finished_at);
    }

    public function test_counter_fields_cast_to_integer(): void
    {
        $run = SwitchSyncRun::factory()->create([
            'ports_created' => 10,
            'ports_updated' => 20,
            'macs_created' => 30,
            'macs_updated' => 40,
        ]);

        $run->refresh();

        $this->assertIsInt($run->ports_created);
        $this->assertIsInt($run->ports_updated);
        $this->assertIsInt($run->macs_created);
        $this->assertIsInt($run->macs_updated);
        $this->assertSame(10, $run->ports_created);
        $this->assertSame(20, $run->ports_updated);
        $this->assertSame(30, $run->macs_created);
        $this->assertSame(40, $run->macs_updated);
    }

    public function test_belongs_to_switch_config(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $run = SwitchSyncRun::factory()->create([
            'switch_config_id' => $switchConfig->id,
        ]);

        $this->assertInstanceOf(SwitchConfig::class, $run->switchConfig);
        $this->assertTrue($run->switchConfig->is($switchConfig));
    }

    public function test_status_pending(): void
    {
        $run = SwitchSyncRun::factory()->create([
            'status' => 'pending',
        ]);

        $this->assertSame('pending', $run->status);
        $this->assertNull($run->started_at);
        $this->assertNull($run->finished_at);
    }

    public function test_status_running(): void
    {
        $run = SwitchSyncRun::factory()->running()->create();

        $this->assertSame('running', $run->status);
        $this->assertNotNull($run->started_at);
    }

    public function test_status_completed(): void
    {
        $run = SwitchSyncRun::factory()->completed()->create();

        $this->assertSame('completed', $run->status);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
    }

    public function test_status_failed(): void
    {
        $run = SwitchSyncRun::factory()->failed()->create();

        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($run->error);
    }

    public function test_default_counter_values(): void
    {
        $run = SwitchSyncRun::factory()->create();

        $this->assertSame(0, $run->ports_created);
        $this->assertSame(0, $run->ports_updated);
        $this->assertSame(0, $run->macs_created);
        $this->assertSame(0, $run->macs_updated);
    }

    public function test_error_is_nullable(): void
    {
        $run = SwitchSyncRun::factory()->create([
            'error' => null,
        ]);

        $run->refresh();

        $this->assertNull($run->error);
    }

    public function test_error_stores_text(): void
    {
        $errorMessage = 'SSH connection timeout after 30 seconds: Unable to connect to 192.168.1.1:22';
        $run = SwitchSyncRun::factory()->create([
            'error' => $errorMessage,
        ]);

        $run->refresh();

        $this->assertSame($errorMessage, $run->error);
    }

    public function test_cascading_delete_when_switch_config_deleted(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $run = SwitchSyncRun::factory()->create([
            'switch_config_id' => $switchConfig->id,
        ]);

        $switchConfig->delete();

        $this->assertDatabaseMissing('switch_sync_runs', ['id' => $run->id]);
    }
}
