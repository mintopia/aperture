<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\SwitchConfig;
use App\Services\Interfaces\FirewallBackendInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\IpAddressActionService;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use App\Services\NtopNgService;
use App\Services\ValueObjects\PortDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

class IpAddressActionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists(IpAddressActionService::class));
    }

    public function test_enable_internet_calls_firewall_update_ip(): void
    {
        $firewall = Mockery::mock(FirewallBackendInterface::class);
        $factory = Mockery::mock(SwitchServiceFactory::class);
        $macResolver = Mockery::mock(MacAddressResolverInterface::class);
        $ntopng = Mockery::mock(NtopNgService::class);

        $firewall->shouldReceive('updateIp')->once()->with('10.0.0.1', Mockery::any());
        $macResolver->shouldReceive('resolveIpToMac')->once()->with('10.0.0.1')->andReturn(null);

        $service = new IpAddressActionService($firewall, $factory, $macResolver, $ntopng);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.1', 'comment' => 'test']);
        $service->enableInternet($ip);
    }

    public function test_enable_internet_links_mac_address_when_resolved(): void
    {
        $firewall = Mockery::mock(FirewallBackendInterface::class);
        $factory = Mockery::mock(SwitchServiceFactory::class);
        $macResolver = Mockery::mock(MacAddressResolverInterface::class);
        $ntopng = Mockery::mock(NtopNgService::class);

        $firewall->shouldReceive('updateIp')->once();
        $macResolver->shouldReceive('resolveIpToMac')->once()->andReturn('aa:bb:cc:dd:ee:ff');

        $service = new IpAddressActionService($firewall, $factory, $macResolver, $ntopng);

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
        $firewall = Mockery::mock(FirewallBackendInterface::class);
        $factory = Mockery::mock(SwitchServiceFactory::class);
        $macResolver = Mockery::mock(MacAddressResolverInterface::class);
        $ntopng = Mockery::mock(NtopNgService::class);

        $firewall->shouldReceive('updateIp')->once();
        $macResolver->shouldReceive('resolveIpToMac')->once()->andReturn(null);

        $service = new IpAddressActionService($firewall, $factory, $macResolver, $ntopng);

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
        $firewall = Mockery::mock(FirewallBackendInterface::class);
        $factory = Mockery::mock(SwitchServiceFactory::class);
        $macResolver = Mockery::mock(MacAddressResolverInterface::class);
        $ntopng = Mockery::mock(NtopNgService::class);

        $firewall->shouldReceive('removeIp')->once()->with('10.0.0.4');

        $service = new IpAddressActionService($firewall, $factory, $macResolver, $ntopng);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.4']);
        $service->disableInternet($ip);
    }

    public function test_disable_internet_does_not_save_ip_fields(): void
    {
        $firewall = Mockery::mock(FirewallBackendInterface::class);
        $factory = Mockery::mock(SwitchServiceFactory::class);
        $macResolver = Mockery::mock(MacAddressResolverInterface::class);
        $ntopng = Mockery::mock(NtopNgService::class);

        $firewall->shouldReceive('removeIp')->once();

        $service = new IpAddressActionService($firewall, $factory, $macResolver, $ntopng);

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
        $firewall = Mockery::mock(FirewallBackendInterface::class);
        $factory = Mockery::mock(SwitchServiceFactory::class);
        $macResolver = Mockery::mock(MacAddressResolverInterface::class);
        $ntopng = Mockery::mock(NtopNgService::class);

        $firewall->shouldReceive('limitIp')->once()->with('10.0.0.6');

        $service = new IpAddressActionService($firewall, $factory, $macResolver, $ntopng);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.6']);
        $service->enableRateLimit($ip);
    }

    public function test_enable_rate_limit_does_not_save_ip_fields(): void
    {
        $firewall = Mockery::mock(FirewallBackendInterface::class);
        $factory = Mockery::mock(SwitchServiceFactory::class);
        $macResolver = Mockery::mock(MacAddressResolverInterface::class);
        $ntopng = Mockery::mock(NtopNgService::class);

        $firewall->shouldReceive('limitIp')->once();

        $service = new IpAddressActionService($firewall, $factory, $macResolver, $ntopng);

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
        $firewall = Mockery::mock(FirewallBackendInterface::class);
        $factory = Mockery::mock(SwitchServiceFactory::class);
        $macResolver = Mockery::mock(MacAddressResolverInterface::class);
        $ntopng = Mockery::mock(NtopNgService::class);

        $firewall->shouldReceive('unlimitIp')->once()->with('10.0.0.8');

        $service = new IpAddressActionService($firewall, $factory, $macResolver, $ntopng);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.8']);
        $service->disableRateLimit($ip);
    }

    public function test_disable_rate_limit_does_not_save_ip_fields(): void
    {
        $firewall = Mockery::mock(FirewallBackendInterface::class);
        $factory = Mockery::mock(SwitchServiceFactory::class);
        $macResolver = Mockery::mock(MacAddressResolverInterface::class);
        $ntopng = Mockery::mock(NtopNgService::class);

        $firewall->shouldReceive('unlimitIp')->once();

        $service = new IpAddressActionService($firewall, $factory, $macResolver, $ntopng);

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

    public function test_shut_port_returns_early_when_no_switch_config_found_for_hostname(): void
    {
        $firewall = Mockery::mock(FirewallBackendInterface::class);
        $factory = Mockery::mock(SwitchServiceFactory::class);
        $macResolver = Mockery::mock(MacAddressResolverInterface::class);
        $ntopng = Mockery::mock(NtopNgService::class);

        // Factory should never be called because we return null before reaching it
        $factory->shouldNotReceive('make');

        $service = new IpAddressActionService($firewall, $factory, $macResolver, $ntopng);

        // Create an IpAddress with a PortDetail that references a hostname not in SwitchConfig
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';

        $portDetail = new PortDetail(hostname: 'unknown-switch.local', interface: 'Gi1/0/1', status: 'connected', adminStatus: 'up', speed: 1000);

        // Inject port info cache directly
        $reflection = new ReflectionClass($ip);
        $cacheProperty = $reflection->getProperty('portInfoCache');
        $cacheProperty->setValue($ip, $portDetail);

        $resolvedProperty = $reflection->getProperty('portInfoResolved');
        $resolvedProperty->setValue($ip, true);

        // Should silently return without dispatching anything (no switch config found)
        $service->shutPort($ip);

        $this->assertTrue(true); // No exception means success
    }

    public function test_shut_port_returns_early_when_no_switch_port_found(): void
    {
        $firewall = Mockery::mock(FirewallBackendInterface::class);
        $factory = Mockery::mock(SwitchServiceFactory::class);
        $macResolver = Mockery::mock(MacAddressResolverInterface::class);
        $ntopng = Mockery::mock(NtopNgService::class);

        // Factory should never be called because we return null before reaching it
        $factory->shouldNotReceive('make');

        $service = new IpAddressActionService($firewall, $factory, $macResolver, $ntopng);

        // Create a switch config but no ports on it
        SwitchConfig::factory()->create(['hostname' => 'switch.local']);

        $ip = new IpAddress;
        $ip->address = '10.0.0.1';

        $portDetail = new PortDetail(hostname: 'switch.local', interface: 'Gi1/0/99', status: 'connected', adminStatus: 'up', speed: 1000);

        // Inject port info cache directly (port Gi1/0/99 does not exist)
        $reflection = new ReflectionClass($ip);
        $cacheProperty = $reflection->getProperty('portInfoCache');
        $cacheProperty->setValue($ip, $portDetail);

        $resolvedProperty = $reflection->getProperty('portInfoResolved');
        $resolvedProperty->setValue($ip, true);

        // Should silently return without dispatching anything (no switch port found)
        $service->shutPort($ip);

        $this->assertTrue(true); // No exception means success
    }

    public function test_unshut_port_returns_early_when_no_switch_config_found_for_hostname(): void
    {
        $firewall = Mockery::mock(FirewallBackendInterface::class);
        $factory = Mockery::mock(SwitchServiceFactory::class);
        $macResolver = Mockery::mock(MacAddressResolverInterface::class);
        $ntopng = Mockery::mock(NtopNgService::class);

        $factory->shouldNotReceive('make');

        $service = new IpAddressActionService($firewall, $factory, $macResolver, $ntopng);

        $ip = new IpAddress;
        $ip->address = '10.0.0.1';

        $portDetail = new PortDetail(hostname: 'nonexistent.local', interface: 'Gi1/0/1', status: 'connected', adminStatus: 'up', speed: 1000);

        $reflection = new ReflectionClass($ip);
        $cacheProperty = $reflection->getProperty('portInfoCache');
        $cacheProperty->setValue($ip, $portDetail);

        $resolvedProperty = $reflection->getProperty('portInfoResolved');
        $resolvedProperty->setValue($ip, true);

        $service->unshutPort($ip);

        $this->assertTrue(true);
    }

    public function test_unshut_port_returns_early_when_no_switch_port_found(): void
    {
        $firewall = Mockery::mock(FirewallBackendInterface::class);
        $factory = Mockery::mock(SwitchServiceFactory::class);
        $macResolver = Mockery::mock(MacAddressResolverInterface::class);
        $ntopng = Mockery::mock(NtopNgService::class);

        $factory->shouldNotReceive('make');

        $service = new IpAddressActionService($firewall, $factory, $macResolver, $ntopng);

        SwitchConfig::factory()->create(['hostname' => 'switch-b.local']);

        $ip = new IpAddress;
        $ip->address = '10.0.0.1';

        $portDetail = new PortDetail(hostname: 'switch-b.local', interface: 'Gi99/0/1', status: 'connected', adminStatus: 'up', speed: 1000);

        $reflection = new ReflectionClass($ip);
        $cacheProperty = $reflection->getProperty('portInfoCache');
        $cacheProperty->setValue($ip, $portDetail);

        $resolvedProperty = $reflection->getProperty('portInfoResolved');
        $resolvedProperty->setValue($ip, true);

        $service->unshutPort($ip);

        $this->assertTrue(true);
    }
}
