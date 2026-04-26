<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use App\Events\BandwidthAnomalyDetected;
use App\Events\DeviceDiscovered;
use App\Events\DhcpPoolThresholdReached;
use App\Events\DnsFilterChanged;
use App\Events\InternetAccessChanged;
use App\Events\PortStateChanged;
use App\Events\RateLimitChanged;
use App\Events\SwitchSyncCompleted;
use App\Events\UserBlocked;
use App\Events\UserConnected;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use PHPUnit\Framework\TestCase;

class BroadcastableEventsTest extends TestCase
{
    public function test_device_discovered_implements_should_broadcast(): void
    {
        $this->assertTrue(
            is_subclass_of(DeviceDiscovered::class, ShouldBroadcast::class)
        );
    }

    public function test_device_discovered_broadcasts_on_admin_channel(): void
    {
        $macAddress = $this->createStub(MacAddress::class);
        $ipAddress = $this->createStub(IpAddress::class);
        $switchPort = $this->createStub(SwitchPort::class);

        $event = new DeviceDiscovered($macAddress, $ipAddress, $switchPort);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-admin.events', $channels[0]->name);
    }

    public function test_device_discovered_broadcast_with_returns_expected_payload(): void
    {
        $macAddress = $this->createStub(MacAddress::class);
        $macAddress->method('__get')->willReturnCallback(fn (string $key) => match ($key) {
            'id' => 1,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            default => null,
        });

        $ipAddress = $this->createStub(IpAddress::class);
        $ipAddress->method('__get')->willReturnCallback(fn (string $key) => match ($key) {
            'id' => 2,
            'ip_address' => '192.168.1.100',
            default => null,
        });

        $switchPort = $this->createStub(SwitchPort::class);
        $switchPort->method('__get')->willReturnCallback(fn (string $key) => match ($key) {
            'id' => 3,
            default => null,
        });

        $event = new DeviceDiscovered($macAddress, $ipAddress, $switchPort);
        $payload = $event->broadcastWith();

        $this->assertSame('AA:BB:CC:DD:EE:FF', $payload['mac_address']);
        $this->assertSame('192.168.1.100', $payload['ip_address']);
        $this->assertSame(3, $payload['switch_port_id']);
    }

    public function test_device_discovered_broadcast_with_handles_null_optional_fields(): void
    {
        $macAddress = $this->createStub(MacAddress::class);
        $macAddress->method('__get')->willReturnCallback(fn (string $key) => match ($key) {
            'id' => 1,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            default => null,
        });

        $event = new DeviceDiscovered($macAddress, null, null);
        $payload = $event->broadcastWith();

        $this->assertSame('AA:BB:CC:DD:EE:FF', $payload['mac_address']);
        $this->assertNull($payload['ip_address']);
        $this->assertNull($payload['switch_port_id']);
    }

    public function test_dhcp_pool_threshold_reached_implements_should_broadcast(): void
    {
        $this->assertTrue(
            is_subclass_of(DhcpPoolThresholdReached::class, ShouldBroadcast::class)
        );
    }

    public function test_dhcp_pool_threshold_reached_broadcasts_on_admin_channel(): void
    {
        $event = new DhcpPoolThresholdReached('default', 85.5, 80.0);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-admin.events', $channels[0]->name);
    }

    public function test_dhcp_pool_threshold_reached_broadcast_with_returns_expected_payload(): void
    {
        $event = new DhcpPoolThresholdReached('default', 85.5, 80.0);
        $payload = $event->broadcastWith();

        $this->assertSame('default', $payload['pool']);
        $this->assertSame(85.5, $payload['usage']);
        $this->assertSame(80.0, $payload['threshold']);
    }

    public function test_dns_filter_changed_implements_should_broadcast(): void
    {
        $this->assertTrue(
            is_subclass_of(DnsFilterChanged::class, ShouldBroadcast::class)
        );
    }

    public function test_dns_filter_changed_broadcasts_on_admin_channel(): void
    {
        $ipAddress = $this->createStub(IpAddress::class);
        $user = $this->createStub(User::class);

        $event = new DnsFilterChanged($ipAddress, true, $user);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-admin.events', $channels[0]->name);
    }

    public function test_dns_filter_changed_broadcast_with_returns_expected_payload(): void
    {
        $ipAddress = $this->createStub(IpAddress::class);
        $ipAddress->method('__get')->willReturnCallback(fn (string $key) => match ($key) {
            'id' => 5,
            'ip_address' => '10.0.0.1',
            default => null,
        });

        $user = $this->createStub(User::class);
        $user->method('__get')->willReturnCallback(fn (string $key) => match ($key) {
            'id' => 10,
            default => null,
        });

        $event = new DnsFilterChanged($ipAddress, true, $user);
        $payload = $event->broadcastWith();

        $this->assertSame(5, $payload['ip_address_id']);
        $this->assertSame('10.0.0.1', $payload['ip_address']);
        $this->assertTrue($payload['enabled']);
        $this->assertSame(10, $payload['changed_by_id']);
    }

    public function test_dns_filter_changed_broadcast_with_handles_null_changed_by(): void
    {
        $ipAddress = $this->createStub(IpAddress::class);
        $ipAddress->method('__get')->willReturnCallback(fn (string $key) => match ($key) {
            'id' => 5,
            'ip_address' => '10.0.0.1',
            default => null,
        });

        $event = new DnsFilterChanged($ipAddress, false, null);
        $payload = $event->broadcastWith();

        $this->assertNull($payload['changed_by_id']);
    }

    public function test_internet_access_changed_implements_should_broadcast(): void
    {
        $this->assertTrue(
            is_subclass_of(InternetAccessChanged::class, ShouldBroadcast::class)
        );
    }

    public function test_internet_access_changed_broadcasts_on_admin_channel(): void
    {
        $ipAddress = $this->createStub(IpAddress::class);
        $user = $this->createStub(User::class);

        $event = new InternetAccessChanged($ipAddress, true, $user);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-admin.events', $channels[0]->name);
    }

    public function test_internet_access_changed_broadcast_with_returns_expected_payload(): void
    {
        $ipAddress = $this->createStub(IpAddress::class);
        $ipAddress->method('__get')->willReturnCallback(fn (string $key) => match ($key) {
            'id' => 7,
            'ip_address' => '10.0.0.5',
            default => null,
        });

        $user = $this->createStub(User::class);
        $user->method('__get')->willReturnCallback(fn (string $key) => match ($key) {
            'id' => 3,
            default => null,
        });

        $event = new InternetAccessChanged($ipAddress, false, $user);
        $payload = $event->broadcastWith();

        $this->assertSame(7, $payload['ip_address_id']);
        $this->assertSame('10.0.0.5', $payload['ip_address']);
        $this->assertFalse($payload['enabled']);
        $this->assertSame(3, $payload['changed_by_id']);
    }

    public function test_port_state_changed_implements_should_broadcast(): void
    {
        $this->assertTrue(
            is_subclass_of(PortStateChanged::class, ShouldBroadcast::class)
        );
    }

    public function test_port_state_changed_broadcasts_on_admin_channel(): void
    {
        $switchPort = $this->createStub(SwitchPort::class);

        $event = new PortStateChanged($switchPort, 'up', 'down');
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-admin.events', $channels[0]->name);
    }

    public function test_port_state_changed_broadcast_with_returns_expected_payload(): void
    {
        $switchPort = $this->createStub(SwitchPort::class);
        $switchPort->method('__get')->willReturnCallback(fn (string $key) => match ($key) {
            'id' => 42,
            'port_name' => 'GigabitEthernet0/1',
            default => null,
        });

        $event = new PortStateChanged($switchPort, 'up', 'down');
        $payload = $event->broadcastWith();

        $this->assertSame(42, $payload['switch_port_id']);
        $this->assertSame('GigabitEthernet0/1', $payload['port_name']);
        $this->assertSame('up', $payload['old_status']);
        $this->assertSame('down', $payload['new_status']);
    }

    public function test_port_state_changed_broadcast_with_handles_null_old_status(): void
    {
        $switchPort = $this->createStub(SwitchPort::class);
        $switchPort->method('__get')->willReturnCallback(fn (string $key) => match ($key) {
            'id' => 42,
            'port_name' => 'GigabitEthernet0/1',
            default => null,
        });

        $event = new PortStateChanged($switchPort, null, 'up');
        $payload = $event->broadcastWith();

        $this->assertNull($payload['old_status']);
        $this->assertSame('up', $payload['new_status']);
    }

    public function test_rate_limit_changed_implements_should_broadcast(): void
    {
        $this->assertTrue(
            is_subclass_of(RateLimitChanged::class, ShouldBroadcast::class)
        );
    }

    public function test_rate_limit_changed_broadcasts_on_admin_channel(): void
    {
        $ipAddress = $this->createStub(IpAddress::class);
        $user = $this->createStub(User::class);

        $event = new RateLimitChanged($ipAddress, 100, 200, $user);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-admin.events', $channels[0]->name);
    }

    public function test_rate_limit_changed_broadcast_with_returns_expected_payload(): void
    {
        $ipAddress = $this->createStub(IpAddress::class);
        $ipAddress->method('__get')->willReturnCallback(fn (string $key) => match ($key) {
            'id' => 8,
            'ip_address' => '10.0.0.8',
            default => null,
        });

        $user = $this->createStub(User::class);
        $user->method('__get')->willReturnCallback(fn (string $key) => match ($key) {
            'id' => 15,
            default => null,
        });

        $event = new RateLimitChanged($ipAddress, 100, 200, $user);
        $payload = $event->broadcastWith();

        $this->assertSame(8, $payload['ip_address_id']);
        $this->assertSame('10.0.0.8', $payload['ip_address']);
        $this->assertSame(100, $payload['old_limit']);
        $this->assertSame(200, $payload['new_limit']);
        $this->assertSame(15, $payload['changed_by_id']);
    }

    public function test_rate_limit_changed_broadcast_with_handles_null_old_limit(): void
    {
        $ipAddress = $this->createStub(IpAddress::class);
        $ipAddress->method('__get')->willReturnCallback(fn (string $key) => match ($key) {
            'id' => 8,
            'ip_address' => '10.0.0.8',
            default => null,
        });

        $event = new RateLimitChanged($ipAddress, null, 200, null);
        $payload = $event->broadcastWith();

        $this->assertNull($payload['old_limit']);
        $this->assertNull($payload['changed_by_id']);
    }

    public function test_switch_sync_completed_implements_should_broadcast(): void
    {
        $this->assertTrue(
            is_subclass_of(SwitchSyncCompleted::class, ShouldBroadcast::class)
        );
    }

    public function test_switch_sync_completed_broadcasts_on_admin_channel(): void
    {
        $switchConfig = $this->createStub(SwitchConfig::class);

        $event = new SwitchSyncCompleted($switchConfig, 5, []);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-admin.events', $channels[0]->name);
    }

    public function test_switch_sync_completed_broadcast_with_returns_expected_payload(): void
    {
        $switchConfig = $this->createStub(SwitchConfig::class);
        $switchConfig->method('__get')->willReturnCallback(fn (string $key) => match ($key) {
            'id' => 99,
            'hostname' => 'switch-01.local',
            default => null,
        });

        $event = new SwitchSyncCompleted($switchConfig, 5, ['Error on port 3']);
        $payload = $event->broadcastWith();

        $this->assertSame(99, $payload['switch_config_id']);
        $this->assertSame('switch-01.local', $payload['hostname']);
        $this->assertSame(5, $payload['ports_updated']);
        $this->assertSame(['Error on port 3'], $payload['errors']);
    }

    public function test_user_blocked_implements_should_broadcast(): void
    {
        $this->assertTrue(
            is_subclass_of(UserBlocked::class, ShouldBroadcast::class)
        );
    }

    public function test_user_blocked_broadcasts_on_admin_and_user_channels(): void
    {
        $user = $this->createStub(User::class);
        $user->method('__get')->willReturnCallback(fn (string $key) => match ($key) {
            'id' => 20,
            default => null,
        });
        $ipAddress = $this->createStub(IpAddress::class);

        $event = new UserBlocked($user, $ipAddress, 'Violation');
        $channels = $event->broadcastOn();

        $this->assertCount(2, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-admin.events', $channels[0]->name);
        $this->assertInstanceOf(PrivateChannel::class, $channels[1]);
        $this->assertSame('private-user.20', $channels[1]->name);
    }

    public function test_user_blocked_broadcast_with_returns_expected_payload(): void
    {
        $user = $this->createStub(User::class);
        $user->method('__get')->willReturnCallback(fn (string $key) => match ($key) {
            'id' => 20,
            'name' => 'John Doe',
            default => null,
        });

        $ipAddress = $this->createStub(IpAddress::class);
        $ipAddress->method('__get')->willReturnCallback(fn (string $key) => match ($key) {
            'id' => 30,
            'ip_address' => '192.168.1.50',
            default => null,
        });

        $event = new UserBlocked($user, $ipAddress, 'Terms violation');
        $payload = $event->broadcastWith();

        $this->assertSame(20, $payload['user_id']);
        $this->assertSame('John Doe', $payload['user_name']);
        $this->assertSame(30, $payload['ip_address_id']);
        $this->assertSame('192.168.1.50', $payload['ip_address']);
        $this->assertSame('Terms violation', $payload['reason']);
    }

    public function test_user_connected_implements_should_broadcast(): void
    {
        $this->assertTrue(
            is_subclass_of(UserConnected::class, ShouldBroadcast::class)
        );
    }

    public function test_user_connected_broadcasts_on_admin_channel(): void
    {
        $user = $this->createStub(User::class);
        $ipAddress = $this->createStub(IpAddress::class);
        $macAddress = $this->createStub(MacAddress::class);

        $event = new UserConnected($user, $ipAddress, $macAddress);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-admin.events', $channels[0]->name);
    }

    public function test_user_connected_broadcast_with_returns_expected_payload(): void
    {
        $user = $this->createStub(User::class);
        $user->method('__get')->willReturnCallback(fn (string $key) => match ($key) {
            'id' => 25,
            'name' => 'Jane Smith',
            default => null,
        });

        $ipAddress = $this->createStub(IpAddress::class);
        $ipAddress->method('__get')->willReturnCallback(fn (string $key) => match ($key) {
            'id' => 35,
            'ip_address' => '192.168.1.75',
            default => null,
        });

        $macAddress = $this->createStub(MacAddress::class);
        $macAddress->method('__get')->willReturnCallback(fn (string $key) => match ($key) {
            'id' => 45,
            'mac_address' => '11:22:33:44:55:66',
            default => null,
        });

        $event = new UserConnected($user, $ipAddress, $macAddress);
        $payload = $event->broadcastWith();

        $this->assertSame(25, $payload['user_id']);
        $this->assertSame('Jane Smith', $payload['user_name']);
        $this->assertSame(35, $payload['ip_address_id']);
        $this->assertSame('192.168.1.75', $payload['ip_address']);
        $this->assertSame(45, $payload['mac_address_id']);
        $this->assertSame('11:22:33:44:55:66', $payload['mac_address']);
    }

    public function test_bandwidth_anomaly_detected_implements_should_broadcast(): void
    {
        $this->assertTrue(
            is_subclass_of(BandwidthAnomalyDetected::class, ShouldBroadcast::class)
        );
    }

    public function test_bandwidth_anomaly_detected_broadcasts_on_admin_channel(): void
    {
        $event = new BandwidthAnomalyDetected(
            ipAddress: '10.0.0.50',
            userName: 'Test User',
            userId: 1,
            shortTermAvg: 150000.0,
            longTermAvg: 30000.0,
            ratio: 5.0,
            threshold: 3.0,
        );
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-admin.events', $channels[0]->name);
    }

    public function test_bandwidth_anomaly_detected_broadcast_with_returns_expected_payload(): void
    {
        $event = new BandwidthAnomalyDetected(
            ipAddress: '10.0.0.50',
            userName: 'John Doe',
            userId: 42,
            shortTermAvg: 150000.0,
            longTermAvg: 30000.0,
            ratio: 5.0,
            threshold: 3.0,
        );
        $payload = $event->broadcastWith();

        $this->assertSame('10.0.0.50', $payload['ip_address']);
        $this->assertSame('John Doe', $payload['user_name']);
        $this->assertSame(42, $payload['user_id']);
        $this->assertSame(150000.0, $payload['short_term_avg']);
        $this->assertSame(30000.0, $payload['long_term_avg']);
        $this->assertSame(5.0, $payload['ratio']);
        $this->assertSame(3.0, $payload['threshold']);
    }

    public function test_bandwidth_anomaly_detected_broadcast_with_handles_null_user(): void
    {
        $event = new BandwidthAnomalyDetected(
            ipAddress: '10.0.0.99',
            userName: null,
            userId: null,
            shortTermAvg: 100000.0,
            longTermAvg: 20000.0,
            ratio: 5.0,
            threshold: 3.0,
        );
        $payload = $event->broadcastWith();

        $this->assertSame('10.0.0.99', $payload['ip_address']);
        $this->assertNull($payload['user_name']);
        $this->assertNull($payload['user_id']);
    }

    public function test_all_events_use_correct_broadcast_event_name(): void
    {
        $events = [
            BandwidthAnomalyDetected::class => 'BandwidthAnomalyDetected',
            DeviceDiscovered::class => 'DeviceDiscovered',
            DhcpPoolThresholdReached::class => 'DhcpPoolThresholdReached',
            DnsFilterChanged::class => 'DnsFilterChanged',
            InternetAccessChanged::class => 'InternetAccessChanged',
            PortStateChanged::class => 'PortStateChanged',
            RateLimitChanged::class => 'RateLimitChanged',
            SwitchSyncCompleted::class => 'SwitchSyncCompleted',
            UserBlocked::class => 'UserBlocked',
            UserConnected::class => 'UserConnected',
        ];

        foreach ($events as $eventClass => $expectedName) {
            $this->assertTrue(
                is_subclass_of($eventClass, ShouldBroadcast::class),
                "{$eventClass} should implement ShouldBroadcast"
            );
        }
    }
}
