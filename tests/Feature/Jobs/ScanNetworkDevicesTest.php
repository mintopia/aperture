<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ScanNetworkDevices;
use App\Models\IntegrationConfig;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\FirewallBackendInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\Interfaces\NetworkInventoryInterface;
use App\Services\ValueObjects\ArpEntry;
use App\Services\ValueObjects\DhcpLease;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ScanNetworkDevicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        IntegrationConfig::setValue('auto_allow', 'enabled', '1');

        // Mock firewall
        $firewall = Mockery::mock(FirewallBackendInterface::class);
        $firewall->shouldReceive('updateIp')->andReturn($firewall);
        $this->app->instance(FirewallBackendInterface::class, $firewall);
    }

    public function test_resolves_mac_for_unlinked_ips(): void
    {
        $ip = IpAddress::factory()->create(['address' => '10.0.0.1', 'mac_address_id' => null]);

        $resolver = Mockery::mock(MacAddressResolverInterface::class);
        $resolver->shouldReceive('resolveIpToMac')
            ->with('10.0.0.1')
            ->andReturn('AA:BB:CC:DD:EE:FF');
        $this->app->instance(MacAddressResolverInterface::class, $resolver);

        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLeases')->andReturn(collect([]));
        $this->app->instance(DhcpInterface::class, $dhcp);

        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('getArpTable')->andReturn(collect([]));
        $this->app->instance(NetworkInventoryInterface::class, $inventory);

        (new ScanNetworkDevices)->handle();

        $ip->refresh();
        $this->assertNotNull($ip->mac_address_id);
        $this->assertDatabaseHas('mac_addresses', ['mac_address' => 'AA:BB:CC:DD:EE:FF']);
    }

    public function test_auto_allows_ip_for_known_allowed_mac(): void
    {
        $mac = MacAddress::factory()->allowed()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        $resolver = Mockery::mock(MacAddressResolverInterface::class);
        $resolver->shouldReceive('resolveIpToMac')->andReturnNull();
        $this->app->instance(MacAddressResolverInterface::class, $resolver);

        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLeases')->andReturn(collect([
            new DhcpLease(ip: '10.0.0.50', mac: 'aa:bb:cc:dd:ee:ff', hostname: 'host', expires: ''),
        ]));
        $this->app->instance(DhcpInterface::class, $dhcp);

        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('getArpTable')->andReturn(collect([]));
        $this->app->instance(NetworkInventoryInterface::class, $inventory);

        (new ScanNetworkDevices)->handle();

        $this->assertDatabaseHas('ip_addresses', [
            'address' => '10.0.0.50',
            'allowed' => true,
            'mac_address_id' => $mac->id,
        ]);
    }

    public function test_detects_xbox_by_oui_prefix_and_auto_allows(): void
    {
        IntegrationConfig::setValue('auto_allow', 'oui_prefixes', '98:5F:D3');

        $resolver = Mockery::mock(MacAddressResolverInterface::class);
        $resolver->shouldReceive('resolveIpToMac')->andReturnNull();
        $this->app->instance(MacAddressResolverInterface::class, $resolver);

        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLeases')->andReturn(collect([
            new DhcpLease(ip: '10.0.0.60', mac: '98:5f:d3:11:22:33', hostname: 'XboxOne', expires: ''),
        ]));
        $this->app->instance(DhcpInterface::class, $dhcp);

        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('getArpTable')->andReturn(collect([]));
        $this->app->instance(NetworkInventoryInterface::class, $inventory);

        (new ScanNetworkDevices)->handle();

        $this->assertDatabaseHas('mac_addresses', [
            'mac_address' => '98:5F:D3:11:22:33',
            'source' => 'xbox',
            'allowed' => true,
        ]);
        $this->assertDatabaseHas('ip_addresses', [
            'address' => '10.0.0.60',
            'allowed' => true,
        ]);
    }

    public function test_does_not_detect_non_matching_oui(): void
    {
        IntegrationConfig::setValue('auto_allow', 'oui_prefixes', '98:5F:D3');

        $resolver = Mockery::mock(MacAddressResolverInterface::class);
        $resolver->shouldReceive('resolveIpToMac')->andReturnNull();
        $this->app->instance(MacAddressResolverInterface::class, $resolver);

        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLeases')->andReturn(collect([
            new DhcpLease(ip: '10.0.0.70', mac: 'AA:BB:CC:11:22:33', hostname: 'laptop', expires: ''),
        ]));
        $this->app->instance(DhcpInterface::class, $dhcp);

        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('getArpTable')->andReturn(collect([]));
        $this->app->instance(NetworkInventoryInterface::class, $inventory);

        (new ScanNetworkDevices)->handle();

        $this->assertDatabaseMissing('mac_addresses', ['mac_address' => 'AA:BB:CC:11:22:33']);
        $this->assertDatabaseMissing('ip_addresses', ['address' => '10.0.0.70']);
    }

    public function test_does_nothing_when_disabled(): void
    {
        IntegrationConfig::setValue('auto_allow', 'enabled', '0');

        $resolver = Mockery::mock(MacAddressResolverInterface::class);
        $resolver->shouldNotReceive('resolveIpToMac');

        $this->app->instance(MacAddressResolverInterface::class, $resolver);

        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldNotReceive('getLeases');

        $this->app->instance(DhcpInterface::class, $dhcp);

        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldNotReceive('getArpTable');

        $this->app->instance(NetworkInventoryInterface::class, $inventory);

        (new ScanNetworkDevices)->handle();
    }

    public function test_scheduled_in_kernel(): void
    {
        $schedule = app(Schedule::class);
        $events = $schedule->events();

        $scanEvents = array_filter($events, function (Event $event): bool {
            return str_contains($event->description, 'ScanNetworkDevices');
        });

        $this->assertNotEmpty($scanEvents);
    }

    public function test_also_processes_arp_entries(): void
    {
        $mac = MacAddress::factory()->allowed()->create(['mac_address' => '11:22:33:44:55:66']);

        $resolver = Mockery::mock(MacAddressResolverInterface::class);
        $resolver->shouldReceive('resolveIpToMac')->andReturnNull();
        $this->app->instance(MacAddressResolverInterface::class, $resolver);

        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLeases')->andReturn(collect([]));
        $this->app->instance(DhcpInterface::class, $dhcp);

        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('getArpTable')->andReturn(collect([
            new ArpEntry(ip: '10.0.0.80', mac: '11:22:33:44:55:66'),
        ]));
        $this->app->instance(NetworkInventoryInterface::class, $inventory);

        (new ScanNetworkDevices)->handle();

        $this->assertDatabaseHas('ip_addresses', [
            'address' => '10.0.0.80',
            'allowed' => true,
            'mac_address_id' => $mac->id,
        ]);
    }

    public function test_auto_allow_links_mac_to_already_allowed_ip(): void
    {
        $ip = IpAddress::factory()->allowed()->create(['address' => '10.0.0.90']);
        $mac = MacAddress::factory()->allowed()->create(['mac_address' => 'CC:DD:EE:FF:00:11']);

        $resolver = Mockery::mock(MacAddressResolverInterface::class);
        $resolver->shouldReceive('resolveIpToMac')->andReturnNull();
        $this->app->instance(MacAddressResolverInterface::class, $resolver);

        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLeases')->andReturn(collect([
            new DhcpLease(ip: '10.0.0.90', mac: 'cc:dd:ee:ff:00:11', hostname: 'host', expires: ''),
        ]));
        $this->app->instance(DhcpInterface::class, $dhcp);

        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('getArpTable')->andReturn(collect([]));
        $this->app->instance(NetworkInventoryInterface::class, $inventory);

        (new ScanNetworkDevices)->handle();

        $ip->refresh();
        $this->assertTrue((bool) $ip->allowed);
        $this->assertSame($mac->id, $ip->mac_address_id);
    }
}
