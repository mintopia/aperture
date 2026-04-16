<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dhcp;

use App\Models\IntegrationConfig;
use App\Services\Dhcp\NullDhcpService;
use App\Services\Dhcp\OpnSenseDhcpService;
use App\Services\Interfaces\DhcpInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionProperty;
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

    public function test_isc_binding_uses_correct_api_paths(): void
    {
        IntegrationConfig::setValue('opnsense', 'dhcp_server', 'isc');
        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');

        $this->app->forgetInstance(DhcpInterface::class);
        $service = $this->app->make(DhcpInterface::class);

        $this->assertInstanceOf(OpnSenseDhcpService::class, $service);
        $this->assertEquals('/api/dhcpv4/leases/search_lease', $this->getProtectedProperty($service, 'leasesPath'));
        $this->assertEquals('', $this->getProtectedProperty($service, 'ipv4RangesPath'));
        $this->assertEquals('', $this->getProtectedProperty($service, 'ipv6RangesPath'));
    }

    public function test_kea_binding_uses_correct_api_paths(): void
    {
        IntegrationConfig::setValue('opnsense', 'dhcp_server', 'kea');
        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');

        $this->app->forgetInstance(DhcpInterface::class);
        $service = $this->app->make(DhcpInterface::class);

        $this->assertInstanceOf(OpnSenseDhcpService::class, $service);
        $this->assertEquals('/api/kea/leases/search', $this->getProtectedProperty($service, 'leasesPath'));
        $this->assertEquals('/api/kea/dhcpv4/search_subnet', $this->getProtectedProperty($service, 'ipv4RangesPath'));
        $this->assertEquals('/api/kea/dhcpv6/search_subnet', $this->getProtectedProperty($service, 'ipv6RangesPath'));
    }

    public function test_dnsmasq_binding_uses_correct_api_paths(): void
    {
        IntegrationConfig::setValue('opnsense', 'dhcp_server', 'dnsmasq');
        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');

        $this->app->forgetInstance(DhcpInterface::class);
        $service = $this->app->make(DhcpInterface::class);

        $this->assertInstanceOf(OpnSenseDhcpService::class, $service);
        $this->assertEquals('/api/dnsmasq/leases/search', $this->getProtectedProperty($service, 'leasesPath'));
        $this->assertEquals('/api/dnsmasq/settings/search_range', $this->getProtectedProperty($service, 'ipv4RangesPath'));
        $this->assertEquals('', $this->getProtectedProperty($service, 'ipv6RangesPath'));
    }

    private function getProtectedProperty(object $object, string $property): mixed
    {
        $reflection = new ReflectionProperty($object, $property);

        return $reflection->getValue($object);
    }
}
