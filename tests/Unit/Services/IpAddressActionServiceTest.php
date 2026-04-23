<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\SwitchConfig;
use App\Services\Interfaces\FirewallBackendInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\Interfaces\NetworkInventoryInterface;
use App\Services\IpAddressActionService;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use App\Services\NtopNgService;
use App\Services\ValueObjects\PortDetail;
use App\Services\ValueObjects\ResolvedPort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use stdClass;
use Tests\TestCase;

class IpAddressActionServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  (FirewallBackendInterface&MockInterface)|null  $firewall
     * @param  (SwitchServiceFactory&MockInterface)|null  $factory
     * @param  (MacAddressResolverInterface&MockInterface)|null  $macResolver
     * @param  (NtopNgService&MockInterface)|null  $ntopng
     * @param  (NetworkInventoryInterface&MockInterface)|null  $inventory
     */
    private function createService(
        FirewallBackendInterface|MockInterface|null $firewall = null,
        SwitchServiceFactory|MockInterface|null $factory = null,
        MacAddressResolverInterface|MockInterface|null $macResolver = null,
        NtopNgService|MockInterface|null $ntopng = null,
        NetworkInventoryInterface|MockInterface|null $inventory = null,
    ): IpAddressActionService {
        /** @var FirewallBackendInterface $resolvedFirewall */
        $resolvedFirewall = $firewall ?? Mockery::mock(FirewallBackendInterface::class);
        /** @var SwitchServiceFactory $resolvedFactory */
        $resolvedFactory = $factory ?? Mockery::mock(SwitchServiceFactory::class);
        /** @var MacAddressResolverInterface $resolvedMacResolver */
        $resolvedMacResolver = $macResolver ?? Mockery::mock(MacAddressResolverInterface::class);
        /** @var NtopNgService $resolvedNtopng */
        $resolvedNtopng = $ntopng ?? Mockery::mock(NtopNgService::class);
        /** @var NetworkInventoryInterface $resolvedInventory */
        $resolvedInventory = $inventory ?? Mockery::mock(NetworkInventoryInterface::class);

        return new IpAddressActionService(
            $resolvedFirewall,
            $resolvedFactory,
            $resolvedMacResolver,
            $resolvedNtopng,
            $resolvedInventory,
        );
    }

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists(IpAddressActionService::class));
    }

    public function test_enable_internet_calls_firewall_update_ip(): void
    {
        /** @var FirewallBackendInterface&MockInterface $firewall */
        $firewall = Mockery::mock(FirewallBackendInterface::class);
        /** @var MacAddressResolverInterface&MockInterface $macResolver */
        $macResolver = Mockery::mock(MacAddressResolverInterface::class);

        $firewall->shouldReceive('updateIp')->once()->with('10.0.0.1', Mockery::any());
        $macResolver->shouldReceive('resolveIpToMac')->once()->with('10.0.0.1')->andReturn(null);

        $service = $this->createService(firewall: $firewall, macResolver: $macResolver);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.1', 'comment' => 'test']);
        $service->enableInternet($ip);
    }

    public function test_enable_internet_links_mac_address_when_resolved(): void
    {
        /** @var FirewallBackendInterface&MockInterface $firewall */
        $firewall = Mockery::mock(FirewallBackendInterface::class);
        /** @var MacAddressResolverInterface&MockInterface $macResolver */
        $macResolver = Mockery::mock(MacAddressResolverInterface::class);

        $firewall->shouldReceive('updateIp')->once();
        $macResolver->shouldReceive('resolveIpToMac')->once()->andReturn('aa:bb:cc:dd:ee:ff');

        $service = $this->createService(firewall: $firewall, macResolver: $macResolver);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.2']);
        $service->enableInternet($ip);

        $ip->refresh();
        $this->assertNotNull($ip->mac_address_id);

        // NormalizeMacAddress cast converts to uppercase colon-separated format
        $mac = MacAddress::where('mac_address', 'AA:BB:CC:DD:EE:FF')->first();
        $this->assertNotNull($mac);
        $this->assertTrue($mac->allowed);
    }

    public function test_enable_internet_does_not_save_ip_fields(): void
    {
        /** @var FirewallBackendInterface&MockInterface $firewall */
        $firewall = Mockery::mock(FirewallBackendInterface::class);
        /** @var MacAddressResolverInterface&MockInterface $macResolver */
        $macResolver = Mockery::mock(MacAddressResolverInterface::class);

        $firewall->shouldReceive('updateIp')->once();
        $macResolver->shouldReceive('resolveIpToMac')->once()->andReturn(null);

        $service = $this->createService(firewall: $firewall, macResolver: $macResolver);

        $ip = IpAddress::factory()->create([
            'address' => '10.0.0.3',
            'internet_enabled' => false,
        ]);
        $originalUpdatedAt = $ip->updated_at;

        $service->enableInternet($ip);

        $ip->refresh();
        // internet_enabled should NOT be changed by the service — the observer handles it
        $this->assertFalse($ip->internet_enabled);
        $this->assertEquals($originalUpdatedAt, $ip->updated_at);
    }

    public function test_disable_internet_calls_firewall_remove_ip(): void
    {
        /** @var FirewallBackendInterface&MockInterface $firewall */
        $firewall = Mockery::mock(FirewallBackendInterface::class);

        $firewall->shouldReceive('removeIp')->once()->with('10.0.0.4');

        $service = $this->createService(firewall: $firewall);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.4']);
        $service->disableInternet($ip);
    }

    public function test_disable_internet_does_not_save_ip_fields(): void
    {
        /** @var FirewallBackendInterface&MockInterface $firewall */
        $firewall = Mockery::mock(FirewallBackendInterface::class);

        $firewall->shouldReceive('removeIp')->once();

        $service = $this->createService(firewall: $firewall);

        $ip = IpAddress::factory()->create([
            'address' => '10.0.0.5',
            'internet_enabled' => true,
        ]);
        $originalUpdatedAt = $ip->updated_at;

        $service->disableInternet($ip);

        $ip->refresh();
        // internet_enabled should NOT be changed by the service
        $this->assertTrue($ip->internet_enabled);
        $this->assertEquals($originalUpdatedAt, $ip->updated_at);
    }

    public function test_enable_rate_limit_calls_firewall_limit_ip(): void
    {
        /** @var FirewallBackendInterface&MockInterface $firewall */
        $firewall = Mockery::mock(FirewallBackendInterface::class);

        $firewall->shouldReceive('limitIp')->once()->with('10.0.0.6');

        $service = $this->createService(firewall: $firewall);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.6']);
        $service->enableRateLimit($ip);
    }

    public function test_enable_rate_limit_does_not_save_ip_fields(): void
    {
        /** @var FirewallBackendInterface&MockInterface $firewall */
        $firewall = Mockery::mock(FirewallBackendInterface::class);

        $firewall->shouldReceive('limitIp')->once();

        $service = $this->createService(firewall: $firewall);

        $ip = IpAddress::factory()->create([
            'address' => '10.0.0.7',
            'rate_limit_enabled' => false,
        ]);
        $originalUpdatedAt = $ip->updated_at;

        $service->enableRateLimit($ip);

        $ip->refresh();
        $this->assertFalse($ip->rate_limit_enabled);
        $this->assertEquals($originalUpdatedAt, $ip->updated_at);
    }

    public function test_disable_rate_limit_calls_firewall_unlimit_ip(): void
    {
        /** @var FirewallBackendInterface&MockInterface $firewall */
        $firewall = Mockery::mock(FirewallBackendInterface::class);

        $firewall->shouldReceive('unlimitIp')->once()->with('10.0.0.8');

        $service = $this->createService(firewall: $firewall);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.8']);
        $service->disableRateLimit($ip);
    }

    public function test_disable_rate_limit_does_not_save_ip_fields(): void
    {
        /** @var FirewallBackendInterface&MockInterface $firewall */
        $firewall = Mockery::mock(FirewallBackendInterface::class);

        $firewall->shouldReceive('unlimitIp')->once();

        $service = $this->createService(firewall: $firewall);

        $ip = IpAddress::factory()->create([
            'address' => '10.0.0.9',
            'rate_limit_enabled' => true,
        ]);
        $originalUpdatedAt = $ip->updated_at;

        $service->disableRateLimit($ip);

        $ip->refresh();
        $this->assertTrue($ip->rate_limit_enabled);
        $this->assertEquals($originalUpdatedAt, $ip->updated_at);
    }

    public function test_get_port_info_returns_port_detail(): void
    {
        /** @var NetworkInventoryInterface&MockInterface $inventory */
        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('resolveIpToPort')
            ->once()
            ->with('10.0.0.1')
            ->andReturn(new ResolvedPort(ip: '10.0.0.1', mac: 'aa:bb:cc:dd:ee:ff', port: '42', switch: ''));
        $inventory->shouldReceive('getPortDetail')
            ->once()
            ->with('42')
            ->andReturn(new PortDetail(hostname: 'switch01.example.com', interface: 'GigabitEthernet0/1', status: 'up', adminStatus: 'up', speed: 1000000000));

        $service = $this->createService(inventory: $inventory);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);
        $port = $service->getPortInfo($ip);

        $this->assertNotNull($port);
        $this->assertSame('switch01.example.com', $port->hostname);
        $this->assertSame('GigabitEthernet0/1', $port->interface);
        $this->assertSame('up', $port->status);
        $this->assertSame('up', $port->adminStatus);
        $this->assertSame(1000000000, $port->speed);
    }

    public function test_get_port_info_returns_null_when_resolve_fails(): void
    {
        /** @var NetworkInventoryInterface&MockInterface $inventory */
        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('resolveIpToPort')->once()->andReturnNull();

        $service = $this->createService(inventory: $inventory);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);
        $this->assertNull($service->getPortInfo($ip));
    }

    public function test_get_port_info_returns_null_when_detail_fails(): void
    {
        /** @var NetworkInventoryInterface&MockInterface $inventory */
        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('resolveIpToPort')
            ->once()
            ->andReturn(new ResolvedPort(ip: '10.0.0.1', mac: 'aa', port: '42', switch: ''));
        $inventory->shouldReceive('getPortDetail')->once()->andReturnNull();

        $service = $this->createService(inventory: $inventory);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);
        $this->assertNull($service->getPortInfo($ip));
    }

    public function test_get_port_info_returns_null_on_exception(): void
    {
        /** @var NetworkInventoryInterface&MockInterface $inventory */
        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('resolveIpToPort')->andThrow(new RuntimeException('Service down'));

        $service = $this->createService(inventory: $inventory);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);
        $this->assertNull($service->getPortInfo($ip));
    }

    public function test_shut_port_returns_early_when_no_switch_config_found_for_hostname(): void
    {
        /** @var SwitchServiceFactory&MockInterface $factory */
        $factory = Mockery::mock(SwitchServiceFactory::class);
        /** @var NetworkInventoryInterface&MockInterface $inventory */
        $inventory = Mockery::mock(NetworkInventoryInterface::class);

        // Factory should never be called because we return null before reaching it
        $factory->shouldNotReceive('make');

        $inventory->shouldReceive('resolveIpToPort')
            ->andReturn(new ResolvedPort(ip: '10.0.0.1', mac: 'aa', port: '42', switch: ''));
        $inventory->shouldReceive('getPortDetail')
            ->andReturn(new PortDetail(hostname: 'unknown-switch.local', interface: 'Gi1/0/1', status: 'connected', adminStatus: 'up', speed: 1000));

        $service = $this->createService(factory: $factory, inventory: $inventory);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);

        // Should silently return without dispatching anything (no switch config found)
        $service->shutPort($ip);

        // No exception means success - factory was never called
        $this->addToAssertionCount(1);
    }

    public function test_shut_port_returns_early_when_no_switch_port_found(): void
    {
        /** @var SwitchServiceFactory&MockInterface $factory */
        $factory = Mockery::mock(SwitchServiceFactory::class);
        /** @var NetworkInventoryInterface&MockInterface $inventory */
        $inventory = Mockery::mock(NetworkInventoryInterface::class);

        // Factory should never be called because we return null before reaching it
        $factory->shouldNotReceive('make');

        // Create a switch config but no ports on it
        SwitchConfig::factory()->create(['hostname' => 'switch.local']);

        $inventory->shouldReceive('resolveIpToPort')
            ->andReturn(new ResolvedPort(ip: '10.0.0.1', mac: 'aa', port: '42', switch: ''));
        $inventory->shouldReceive('getPortDetail')
            ->andReturn(new PortDetail(hostname: 'switch.local', interface: 'Gi1/0/99', status: 'connected', adminStatus: 'up', speed: 1000));

        $service = $this->createService(factory: $factory, inventory: $inventory);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);

        // Should silently return without dispatching anything (no switch port found)
        $service->shutPort($ip);

        // No exception means success - factory was never called
        $this->addToAssertionCount(1);
    }

    public function test_unshut_port_returns_early_when_no_switch_config_found_for_hostname(): void
    {
        /** @var SwitchServiceFactory&MockInterface $factory */
        $factory = Mockery::mock(SwitchServiceFactory::class);
        /** @var NetworkInventoryInterface&MockInterface $inventory */
        $inventory = Mockery::mock(NetworkInventoryInterface::class);

        $factory->shouldNotReceive('make');

        $inventory->shouldReceive('resolveIpToPort')
            ->andReturn(new ResolvedPort(ip: '10.0.0.1', mac: 'aa', port: '42', switch: ''));
        $inventory->shouldReceive('getPortDetail')
            ->andReturn(new PortDetail(hostname: 'nonexistent.local', interface: 'Gi1/0/1', status: 'connected', adminStatus: 'up', speed: 1000));

        $service = $this->createService(factory: $factory, inventory: $inventory);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);

        $service->unshutPort($ip);

        $this->addToAssertionCount(1);
    }

    public function test_unshut_port_returns_early_when_no_switch_port_found(): void
    {
        /** @var SwitchServiceFactory&MockInterface $factory */
        $factory = Mockery::mock(SwitchServiceFactory::class);
        /** @var NetworkInventoryInterface&MockInterface $inventory */
        $inventory = Mockery::mock(NetworkInventoryInterface::class);

        $factory->shouldNotReceive('make');

        SwitchConfig::factory()->create(['hostname' => 'switch-b.local']);

        $inventory->shouldReceive('resolveIpToPort')
            ->andReturn(new ResolvedPort(ip: '10.0.0.1', mac: 'aa', port: '42', switch: ''));
        $inventory->shouldReceive('getPortDetail')
            ->andReturn(new PortDetail(hostname: 'switch-b.local', interface: 'Gi99/0/1', status: 'connected', adminStatus: 'up', speed: 1000));

        $service = $this->createService(factory: $factory, inventory: $inventory);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);

        $service->unshutPort($ip);

        $this->addToAssertionCount(1);
    }

    public function test_update_usage_updates_received_and_sent(): void
    {
        /** @var NtopNgService&MockInterface $ntopng */
        $ntopng = Mockery::mock(NtopNgService::class);
        $mockStats = new stdClass;
        $mockStats->rsp = new stdClass;
        $mockStats->rsp->{'bytes.rcvd'} = 1000;
        $mockStats->rsp->{'bytes.sent'} = 2000;

        $ntopng->shouldReceive('getStats')
            ->with('10.0.0.1')
            ->once()
            ->andReturn($mockStats);

        $service = $this->createService(ntopng: $ntopng);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);
        $service->updateUsage($ip);

        $ip->refresh();
        $this->assertEquals(1000, $ip->received);
        $this->assertEquals(2000, $ip->sent);
    }

    public function test_update_usage_logs_warning_on_exception(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('Failed to update usage for IP address', Mockery::on(function (array $context): bool {
                return $context['ip'] === '10.0.0.1' && str_contains($context['error'], 'Service unavailable');
            }));

        /** @var NtopNgService&MockInterface $ntopng */
        $ntopng = Mockery::mock(NtopNgService::class);
        $ntopng->shouldReceive('getStats')
            ->with('10.0.0.1')
            ->once()
            ->andThrow(new RuntimeException('Service unavailable'));

        $service = $this->createService(ntopng: $ntopng);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);
        $service->updateUsage($ip);

        // Should not throw - error is logged instead
        $this->addToAssertionCount(1);
    }

    public function test_get_stats_returns_stats_from_ntopng(): void
    {
        /** @var NtopNgService&MockInterface $ntopng */
        $ntopng = Mockery::mock(NtopNgService::class);
        $mockStats = new stdClass;
        $mockStats->rsp = new stdClass;

        $ntopng->shouldReceive('getStats')
            ->with('10.0.0.1')
            ->once()
            ->andReturn($mockStats);

        $service = $this->createService(ntopng: $ntopng);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);
        $result = $service->getStats($ip);

        $this->assertSame($mockStats, $result);
    }
}
