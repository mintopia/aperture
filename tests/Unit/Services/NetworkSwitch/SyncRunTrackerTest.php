<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch;

use App\Models\SwitchConfig;
use App\Models\SwitchSyncRun;
use App\Services\NetworkSwitch\SyncRunTracker;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SyncRunTrackerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_start_creates_running_sync_run(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $tracker = new SyncRunTracker;

        $run = $tracker->start($switchConfig);

        $this->assertInstanceOf(SwitchSyncRun::class, $run);
        $this->assertSame('running', $run->status);
        $this->assertSame($switchConfig->id, $run->switch_config_id);
    }

    public function test_complete_updates_status_and_counters(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $tracker = new SyncRunTracker;
        $run = $tracker->start($switchConfig);

        $tracker->complete($run, portsCreated: 2, portsUpdated: 5, macsCreated: 10, macsUpdated: 3);
        $run->refresh();

        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->ports_created);
        $this->assertSame(5, $run->ports_updated);
        $this->assertNotNull($run->finished_at);
    }

    public function test_fail_updates_status_and_records_error(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $tracker = new SyncRunTracker;
        $run = $tracker->start($switchConfig);

        $tracker->fail($run, 'Connection refused');
        $run->refresh();

        $this->assertSame('failed', $run->status);
        $this->assertSame('Connection refused', $run->error);
    }
}
