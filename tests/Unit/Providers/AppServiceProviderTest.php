<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Services\BorealisService;
use App\Services\Dhcp\OpnSenseDhcpService;
use App\Services\Firewalls\OpnSense;
use App\Services\Interfaces\AuthProviderInterface;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\DnsBlockingInterface;
use App\Services\Interfaces\FirewallBackendInterface;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\LibreNmsService;
use App\Services\NetworkSwitch\CiscoSwitchAdapter;
use App\Services\NtopNgService;
use App\Services\PiHole\PiHoleService;
use Tests\TestCase;

class AppServiceProviderTest extends TestCase
{
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
        config([
            'aperture.borealis.client_id' => 'test-id',
            'aperture.borealis.client_secret' => 'test-secret',
            'aperture.borealis.endpoint' => 'http://localhost',
        ]);

        $service = $this->app->make(BorealisService::class);
        $this->assertInstanceOf(BorealisService::class, $service);
    }

    public function test_boot_registers_libre_nms_service_singleton(): void
    {
        config([
            'aperture.librenms.endpoint' => 'http://localhost',
            'aperture.librenms.api_token' => 'token',
        ]);

        $service = $this->app->make(LibreNmsService::class);
        $this->assertInstanceOf(LibreNmsService::class, $service);
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
        config([
            'aperture.dhcp.endpoint' => 'http://127.0.0.1:19199',
            'aperture.dhcp.key' => 'key',
            'aperture.dhcp.secret' => 'secret',
            'aperture.dhcp.verify' => false,
        ]);

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
}
