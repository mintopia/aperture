<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dhcp;

use App\Models\IntegrationConfig;
use App\Services\Dhcp\NullDhcpService;
use App\Services\Dhcp\OpnSenseDhcpService;
use App\Services\Interfaces\DhcpInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DhcpServiceBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_null_service_when_dhcp_server_is_empty(): void
    {
        IntegrationConfig::setValue('opnsense', 'dhcp_server', '');

        $this->app->forgetInstance(DhcpInterface::class);
        $service = $this->app->make(DhcpInterface::class);

        $this->assertInstanceOf(NullDhcpService::class, $service);
    }

    public function test_resolves_null_service_when_dhcp_server_is_not_set(): void
    {
        $this->app->forgetInstance(DhcpInterface::class);
        $service = $this->app->make(DhcpInterface::class);

        $this->assertInstanceOf(NullDhcpService::class, $service);
    }

    public function test_resolves_opnsense_service_when_dhcp_server_is_isc(): void
    {
        IntegrationConfig::setValue('opnsense', 'dhcp_server', 'isc');
        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');

        $this->app->forgetInstance(DhcpInterface::class);
        $service = $this->app->make(DhcpInterface::class);

        $this->assertInstanceOf(OpnSenseDhcpService::class, $service);
    }

    public function test_resolves_opnsense_service_when_dhcp_server_is_kea(): void
    {
        IntegrationConfig::setValue('opnsense', 'dhcp_server', 'kea');
        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');

        $this->app->forgetInstance(DhcpInterface::class);
        $service = $this->app->make(DhcpInterface::class);

        $this->assertInstanceOf(OpnSenseDhcpService::class, $service);
    }

    public function test_resolves_opnsense_service_when_dhcp_server_is_dnsmasq(): void
    {
        IntegrationConfig::setValue('opnsense', 'dhcp_server', 'dnsmasq');
        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');

        $this->app->forgetInstance(DhcpInterface::class);
        $service = $this->app->make(DhcpInterface::class);

        $this->assertInstanceOf(OpnSenseDhcpService::class, $service);
    }
}
