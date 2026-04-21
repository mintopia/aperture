<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\IpAddress;
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
