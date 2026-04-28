<?php

declare(strict_types=1);

namespace Tests\Feature\Listeners;

use App\Events\BandwidthAnomalyDetected;
use App\Events\DeviceDiscovered;
use App\Events\DhcpPoolThresholdReached;
use App\Events\DnsFilterChanged;
use App\Events\InternetAccessChanged;
use App\Events\PortStateChanged;
use App\Events\RateLimitChanged;
use App\Events\SwitchSyncCompleted;
use App\Events\SwitchUnreachable;
use App\Events\UserBlocked;
use App\Events\UserConnected;
use App\Listeners\RecordBroadcastEvent;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Models\SystemEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use stdClass;
use Tests\TestCase;

class RecordBroadcastEventTest extends TestCase
{
    use LazilyRefreshDatabase;

    private RecordBroadcastEvent $listener;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->listener = new RecordBroadcastEvent;
    }

    public function test_records_user_connected_event(): void
    {
        $user = User::factory()->create(['nickname' => 'alice']);
        $ip = IpAddress::factory()->create(['address' => '10.0.0.42']);
        $mac = MacAddress::factory()->create();

        $event = new UserConnected($user, $ip, $mac);
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'UserConnected',
            'level' => 'info',
            'message' => 'alice connected from 10.0.0.42',
        ]);
    }

    public function test_records_device_discovered_event(): void
    {
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
        $ip = IpAddress::factory()->create(['address' => '10.0.0.50']);

        $event = new DeviceDiscovered($mac, $ip, null);
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'DeviceDiscovered',
            'level' => 'info',
            'message' => 'New device AA:BB:CC:DD:EE:FF discovered on 10.0.0.50',
        ]);
    }

    public function test_records_device_discovered_without_ip(): void
    {
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        $event = new DeviceDiscovered($mac, null, null);
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'DeviceDiscovered',
            'message' => 'New device AA:BB:CC:DD:EE:FF discovered',
        ]);
    }

    public function test_records_switch_sync_completed_event(): void
    {
        $switch = SwitchConfig::factory()->create(['hostname' => 'core-sw-01']);

        $event = new SwitchSyncCompleted($switch, 5, []);
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'SwitchSyncCompleted',
            'level' => 'info',
            'message' => 'Switch core-sw-01 sync completed (5 ports updated)',
        ]);
    }

    public function test_records_switch_sync_completed_with_errors(): void
    {
        $switch = SwitchConfig::factory()->create(['hostname' => 'core-sw-01']);

        $event = new SwitchSyncCompleted($switch, 5, ['error1', 'error2']);
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'SwitchSyncCompleted',
            'message' => 'Switch core-sw-01 sync completed (5 ports updated) - 2 errors',
        ]);
    }

    public function test_records_dhcp_pool_threshold_reached_event(): void
    {
        $event = new DhcpPoolThresholdReached('LAN', 92.0, 90.0);
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'DhcpPoolThresholdReached',
            'level' => 'warning',
            'message' => 'DHCP pool LAN reached 92% utilisation',
        ]);
    }

    public function test_records_internet_access_changed_event(): void
    {
        $ip = IpAddress::factory()->create(['address' => '10.0.0.10']);

        $event = new InternetAccessChanged($ip, true, null);
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'InternetAccessChanged',
            'level' => 'info',
            'message' => 'Internet access enabled for 10.0.0.10',
        ]);
    }

    public function test_records_internet_access_disabled_event(): void
    {
        $ip = IpAddress::factory()->create(['address' => '10.0.0.10']);

        $event = new InternetAccessChanged($ip, false, null);
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'message' => 'Internet access disabled for 10.0.0.10',
        ]);
    }

    public function test_records_rate_limit_changed_event(): void
    {
        $ip = IpAddress::factory()->create(['address' => '10.0.0.10']);

        $event = new RateLimitChanged($ip, 100, 200, null);
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'RateLimitChanged',
            'level' => 'info',
            'message' => 'Rate limit changed for 10.0.0.10 from 100 to 200',
        ]);
    }

    public function test_records_user_blocked_event(): void
    {
        $user = User::factory()->create(['nickname' => 'bob']);
        $ip = IpAddress::factory()->create(['address' => '10.0.0.5']);

        $event = new UserBlocked($user, $ip, 'Terms violation');
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'UserBlocked',
            'level' => 'critical',
            'message' => 'bob blocked on 10.0.0.5: Terms violation',
        ]);
    }

    public function test_records_dns_filter_changed_event(): void
    {
        $ip = IpAddress::factory()->create(['address' => '10.0.0.10']);

        $event = new DnsFilterChanged($ip, true, null);
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'DnsFilterChanged',
            'level' => 'info',
            'message' => 'DNS filter enabled for 10.0.0.10',
        ]);
    }

    public function test_records_switch_unreachable_event(): void
    {
        $switch = SwitchConfig::factory()->create(['hostname' => 'edge-sw-03']);

        $event = new SwitchUnreachable($switch, 3);
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'SwitchUnreachable',
            'level' => 'critical',
            'message' => 'Switch edge-sw-03 unreachable after 3 failures',
        ]);
    }

    public function test_records_bandwidth_anomaly_detected_event(): void
    {
        $event = new BandwidthAnomalyDetected('10.0.0.42', 'alice', 1, 5000.0, 1000.0, 5.0, 3.0);
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'BandwidthAnomalyDetected',
            'level' => 'warning',
            'message' => 'Bandwidth anomaly detected on 10.0.0.42',
        ]);
    }

    public function test_records_port_state_changed_event(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $port = SwitchPort::factory()->for($switchConfig)->create(['port_name' => 'Gi1/0/1']);

        $event = new PortStateChanged($port, 'up', 'down');
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'PortStateChanged',
            'level' => 'info',
            'message' => 'Port Gi1/0/1 changed to down',
        ]);
    }

    public function test_stores_broadcast_data_as_json(): void
    {
        $user = User::factory()->create(['nickname' => 'alice']);
        $ip = IpAddress::factory()->create(['address' => '10.0.0.42']);
        $mac = MacAddress::factory()->create();

        $event = new UserConnected($user, $ip, $mac);
        $this->listener->handleBroadcastEvent($event);

        $stored = SystemEvent::where('type', 'UserConnected')->first();
        $this->assertNotNull($stored);
        $this->assertIsArray($stored->data);
        $this->assertSame($user->id, $stored->data['user_id']);
        $this->assertSame('10.0.0.42', $stored->data['ip_address']);
    }

    public function test_ignores_non_broadcast_events(): void
    {
        $this->listener->handleWildcard('eloquent.created: App\Models\User', [new stdClass]);

        $this->assertDatabaseCount('system_events', 0);
    }
}
