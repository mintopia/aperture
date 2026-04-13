<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ScanNetworkDevices;
use App\Models\IntegrationConfig;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\Interfaces\NetworkInventoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScanNetworkDevicesConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_reads_enabled_from_integration_config(): void
    {
        IntegrationConfig::setValue('auto_allow', 'enabled', '1');

        $this->mock(MacAddressResolverInterface::class);
        $dhcp = $this->mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLeases')->andReturn(collect());
        $inventory = $this->mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('getArpTable')->andReturn(collect());

        config(['aperture.auto_allow.enabled' => false]);

        $job = new ScanNetworkDevices;
        $job->handle();

        $dhcp->shouldHaveReceived('getLeases');
    }

    public function test_scan_disabled_via_integration_config_skips_work(): void
    {
        IntegrationConfig::setValue('auto_allow', 'enabled', '0');

        $dhcp = $this->mock(DhcpInterface::class);
        $dhcp->shouldNotReceive('getLeases');
        $this->mock(MacAddressResolverInterface::class);
        $this->mock(NetworkInventoryInterface::class);

        config(['aperture.auto_allow.enabled' => true]);

        $job = new ScanNetworkDevices;
        $job->handle();

        $dhcp->shouldNotHaveReceived('getLeases');
    }
}
