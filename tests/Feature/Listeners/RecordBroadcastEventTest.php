<?php

declare(strict_types=1);

namespace Tests\Feature\Listeners;

use App\Events\BandwidthAnomalyDetected;
use App\Events\DhcpPoolThresholdReached;
use App\Events\InternetAccessChanged;
use App\Events\SwitchUnreachable;
use App\Listeners\RecordBroadcastEvent;
use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\SwitchConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RecordBroadcastEventTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function listener(): RecordBroadcastEvent
    {
        return new RecordBroadcastEvent;
    }

    public function test_switch_unreachable_records_critical_audit_with_subject(): void
    {
        $switch = SwitchConfig::factory()->create(['name' => 'core-sw']);

        $this->listener()->handleBroadcastEvent(new SwitchUnreachable($switch, 3));

        $log = AuditLog::where('action', 'switch.unreachable')->firstOrFail();
        $this->assertSame('critical', $log->severity);
        $this->assertSame('network', $log->process);
        $this->assertSame($switch->getMorphClass(), $log->subject_type);
        $this->assertSame($switch->id, $log->subject_id);
        $this->assertNotNull($log->metadata);
        $this->assertSame(3, $log->metadata['failure_count']);
    }

    public function test_bandwidth_anomaly_records_warning_audit(): void
    {
        $this->listener()->handleBroadcastEvent(
            new BandwidthAnomalyDetected('10.0.0.5', 'alice', 1, 5000.0, 1000.0, 5.0, 3.0)
        );

        $log = AuditLog::where('action', 'bandwidth.anomaly')->firstOrFail();
        $this->assertSame('warning', $log->severity);
        $this->assertNull($log->subject_type);
        $this->assertNotNull($log->metadata);
        $this->assertSame('10.0.0.5', $log->metadata['ip_address']);
    }

    public function test_dhcp_threshold_records_warning_audit(): void
    {
        $this->listener()->handleBroadcastEvent(
            new DhcpPoolThresholdReached(pool: 'lan', usage: 92.0, threshold: 90.0, addressFamily: 'ipv4')
        );

        $log = AuditLog::where('action', 'dhcp.threshold_reached')->firstOrFail();
        $this->assertSame('warning', $log->severity);
        $this->assertNotNull($log->metadata);
        $this->assertSame('lan', $log->metadata['pool']);
    }

    public function test_already_audited_actor_event_is_not_recorded(): void
    {
        $ip = IpAddress::factory()->create();
        $user = User::factory()->create();

        $this->listener()->handleBroadcastEvent(new InternetAccessChanged($ip, true, $user));

        $this->assertDatabaseMissing('audit_logs', ['action' => 'internet_access_changed']);
        $this->assertSame(0, AuditLog::count());
    }
}
