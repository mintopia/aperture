<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Models\IntegrationConfig;
use App\Services\BorealisService;
use App\Services\CachedNetworkInventoryService;
use App\Services\Dhcp\OpnSenseDhcpService;
use App\Services\Firewalls\OpnSense;
use App\Services\Interfaces\AuthProviderInterface;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\DnsBlockingInterface;
use App\Services\Interfaces\FirewallBackendInterface;
use App\Services\Interfaces\NetworkInventoryInterface;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\NetworkSwitch\CiscoSwitchAdapter;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use App\Services\NtopNgService;
use App\Services\PiHole\PiHoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppServiceProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_registers_auth_provider_interface_binding(): void
    {
        $this->assertInstanceOf(
            AuthProviderInterface::class,
            $this->app->make(AuthProviderInterface::class)
        );
    }

    public function test_boot_registers_ntop_ng_service_singleton(): void
    {
        config([
            'aperture.ntopng.endpoint' => 'http://localhost:3000',
            'aperture.ntopng.username' => 'admin',
            'aperture.ntopng.password' => 'admin',
            'aperture.ntopng.interface' => 1,
        ]);

        $service = $this->app->make(NtopNgService::class);
        $this->assertInstanceOf(NtopNgService::class, $service);

        // Verify it's a singleton - same instance returned
        $service2 = $this->app->make(NtopNgService::class);
        $this->assertSame($service, $service2);
    }

    public function test_boot_registers_borealis_service_singleton(): void
    {
        IntegrationConfig::setValue('borealis', 'endpoint', 'https://auth.test.local');
        IntegrationConfig::setValue('borealis', 'client_id', 'test-client-id');
        IntegrationConfig::setValue('borealis', 'client_secret', 'test-secret', true);

        $service = $this->app->make(BorealisService::class);
        $this->assertInstanceOf(BorealisService::class, $service);
    }

    public function test_boot_registers_borealis_service_singleton_from_db_config(): void
    {
        IntegrationConfig::setValue('borealis', 'endpoint', 'https://auth.test.local');
        IntegrationConfig::setValue('borealis', 'client_id', 'test-client-id');
        IntegrationConfig::setValue('borealis', 'client_secret', 'test-secret', true);

        $service = $this->app->make(BorealisService::class);

        $this->assertInstanceOf(BorealisService::class, $service);
    }

    public function test_boot_registers_network_inventory_interface_singleton(): void
    {
        config([
            'aperture.librenms.endpoint' => 'http://localhost',
            'aperture.librenms.api_token' => 'token',
        ]);

        $service = $this->app->make(NetworkInventoryInterface::class);
        $this->assertInstanceOf(CachedNetworkInventoryService::class, $service);
    }

    public function test_registers_firewall_backend_interface_binding(): void
    {
        config([
            'aperture.opnsense.endpoint' => 'http://127.0.0.1:19199',
            'aperture.opnsense.key' => 'key',
            'aperture.opnsense.secret' => 'secret',
            'aperture.opnsense.verify' => false,
            'aperture.opnsense.zoneid' => 1,
            'aperture.opnsense.ratelimitUpUuid' => 'up-uuid',
            'aperture.opnsense.ratelimitDownUuid' => 'down-uuid',
        ]);

        $instance = $this->app->make(FirewallBackendInterface::class);
        $this->assertInstanceOf(OpnSense::class, $instance);
    }

    public function test_registers_dhcp_interface_binding(): void
    {
        IntegrationConfig::setValue('opnsense', 'dhcp_server', 'isc');
        IntegrationConfig::setValue('opnsense', 'endpoint', 'http://127.0.0.1:19199');
        IntegrationConfig::setValue('opnsense', 'key', 'key');
        IntegrationConfig::setValue('opnsense', 'secret', 'secret');

        $this->app->forgetInstance(DhcpInterface::class);
        $instance = $this->app->make(DhcpInterface::class);
        $this->assertInstanceOf(OpnSenseDhcpService::class, $instance);
    }

    public function test_registers_dns_blocking_interface_binding(): void
    {
        config([
            'aperture.pihole.endpoint' => 'http://127.0.0.1:8080',
            'aperture.pihole.password' => 'test-password',
            'aperture.pihole.noblock_group_id' => 1,
            'aperture.pihole.verify' => false,
        ]);

        $instance = $this->app->make(DnsBlockingInterface::class);
        $this->assertInstanceOf(PiHoleService::class, $instance);
    }

    public function test_registers_network_switch_interface_binding(): void
    {
        config([
            'aperture.cisco.hostname' => 'switch.local',
            'aperture.cisco.username' => 'admin',
            'aperture.cisco.password' => 'password',
            'aperture.cisco.enablePassword' => 'enable',
            'aperture.cisco.timeout' => 5,
        ]);

        $instance = $this->app->make(NetworkSwitchInterface::class);
        $this->assertInstanceOf(CiscoSwitchAdapter::class, $instance);
    }

    public function test_registers_switch_service_factory_singleton(): void
    {
        $this->assertInstanceOf(SwitchServiceFactory::class, $this->app->make(SwitchServiceFactory::class));
    }
}
