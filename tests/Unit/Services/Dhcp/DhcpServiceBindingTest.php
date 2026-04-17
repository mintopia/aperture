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
        $this->assertEquals('/api/dhcpv6/leases/search_lease', $this->getProtectedProperty($service, 'ipv6RangesPath'));
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
        $this->assertEquals('/api/dnsmasq/settings/search_range', $this->getProtectedProperty($service, 'ipv6RangesPath'));
    }

    public function test_dnsmasq_binding_passes_correct_field_maps(): void
    {
        IntegrationConfig::setValue('opnsense', 'dhcp_server', 'dnsmasq');
        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');

        $this->app->forgetInstance(DhcpInterface::class);
        $service = $this->app->make(DhcpInterface::class);

        $this->assertInstanceOf(OpnSenseDhcpService::class, $service);

        /** @var array<string, string> $leaseMap */
        $leaseMap = $this->getProtectedProperty($service, 'leaseFieldMap');
        $this->assertEquals('hwaddr', $leaseMap['mac']);
        $this->assertEquals('expire', $leaseMap['expires']);

        /** @var array<string, string> $rangeMap */
        $rangeMap = $this->getProtectedProperty($service, 'rangeFieldMap');
        $this->assertEquals('start_addr', $rangeMap['range_from']);
        $this->assertEquals('end_addr', $rangeMap['range_to']);
        $this->assertEquals('%set_tag', $rangeMap['description']);
        $this->assertEquals('subnet_mask', $rangeMap['subnet_mask']);
    }

    public function test_kea_binding_passes_correct_field_maps(): void
    {
        IntegrationConfig::setValue('opnsense', 'dhcp_server', 'kea');
        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');

        $this->app->forgetInstance(DhcpInterface::class);
        $service = $this->app->make(DhcpInterface::class);

        $this->assertInstanceOf(OpnSenseDhcpService::class, $service);

        /** @var array<string, string> $leaseMap */
        $leaseMap = $this->getProtectedProperty($service, 'leaseFieldMap');
        $this->assertEquals('hwaddr', $leaseMap['mac']);
        $this->assertEquals('expire', $leaseMap['expires']);
        $this->assertEquals('state', $leaseMap['status']);
    }

    public function test_isc_binding_uses_default_field_maps(): void
    {
        IntegrationConfig::setValue('opnsense', 'dhcp_server', 'isc');
        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.local');

        $this->app->forgetInstance(DhcpInterface::class);
        $service = $this->app->make(DhcpInterface::class);

        $this->assertInstanceOf(OpnSenseDhcpService::class, $service);

        /** @var array<string, string> $leaseMap */
        $leaseMap = $this->getProtectedProperty($service, 'leaseFieldMap');
        $this->assertEquals('mac', $leaseMap['mac']);
        $this->assertEquals('ends', $leaseMap['expires']);
        $this->assertEquals('status', $leaseMap['status']);

        /** @var array<string, string> $rangeMap */
        $rangeMap = $this->getProtectedProperty($service, 'rangeFieldMap');
        $this->assertEquals('range_from', $rangeMap['range_from']);
        $this->assertEquals('range_to', $rangeMap['range_to']);
        $this->assertEquals('description', $rangeMap['description']);
    }

    private function getProtectedProperty(object $object, string $property): mixed
    {
        $reflection = new ReflectionProperty($object, $property);

        return $reflection->getValue($object);
    }
}
