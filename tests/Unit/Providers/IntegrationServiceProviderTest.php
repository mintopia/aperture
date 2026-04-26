<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Models\CapabilityAssignment;
use App\Models\IntegrationConfig;
use App\Services\BorealisService;
use App\Services\CachedNetworkInventoryService;
use App\Services\Firewalls\OpnSenseApiService;
use App\Services\Integration\IntegrationTesterRegistry;
use App\Services\Interfaces\CaptivePortalInterface;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\DnsFilteringInterface;
use App\Services\Interfaces\IpBandwidthInterface;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Interfaces\MetricsProviderInterface;
use App\Services\Interfaces\NetworkInventoryInterface;
use App\Services\Interfaces\PortBandwidthInterface;
use App\Services\Interfaces\PortErrorsInterface;
use App\Services\Interfaces\PortMacInterface;
use App\Services\Interfaces\RateLimitingInterface;
use App\Services\LibreNms\LibreNmsIpMacResolver;
use App\Services\LibreNms\LibreNmsPortMac;
use App\Services\LibreNms\LibreNmsService;
use App\Services\Null\NullCaptivePortal;
use App\Services\Null\NullDhcpService;
use App\Services\Null\NullDnsFiltering;
use App\Services\Null\NullIpBandwidth;
use App\Services\Null\NullIpMacResolver;
use App\Services\Null\NullPortBandwidth;
use App\Services\Null\NullPortErrors;
use App\Services\Null\NullPortMac;
use App\Services\Null\NullRateLimiter;
use App\Services\OpnSense\OpnSenseCaptivePortal;
use App\Services\OpnSense\OpnSenseClient;
use App\Services\OpnSense\OpnSenseDhcpService;
use App\Services\OpnSense\OpnSenseRateLimiter;
use App\Services\PiHole\PiHoleService;
use App\Services\Prometheus\PrometheusIpBandwidth;
use App\Services\Prometheus\PrometheusPortBandwidth;
use App\Services\Prometheus\PrometheusPortErrors;
use App\Services\Prometheus\PrometheusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationServiceProviderTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------
    // Shared singletons
    // -------------------------------------------------------

    public function test_opnsense_client_is_registered_as_singleton(): void
    {
        IntegrationConfig::setValue('opnsense', 'endpoint', 'http://opnsense.local');
        IntegrationConfig::setValue('opnsense', 'key', 'test-key', true);
        IntegrationConfig::setValue('opnsense', 'secret', 'test-secret', true);

        $this->app->forgetInstance(OpnSenseClient::class);

        $client1 = $this->app->make(OpnSenseClient::class);
        $client2 = $this->app->make(OpnSenseClient::class);

        $this->assertInstanceOf(OpnSenseClient::class, $client1);
        $this->assertSame($client1, $client2);
    }

    public function test_prometheus_service_is_registered_as_singleton(): void
    {
        IntegrationConfig::setValue('prometheus', 'endpoint', 'http://prometheus.local:9090');
        IntegrationConfig::setValue('prometheus', 'bearer_token', 'test-token');

        $this->app->forgetInstance(PrometheusService::class);

        $service1 = $this->app->make(PrometheusService::class);
        $service2 = $this->app->make(PrometheusService::class);

        $this->assertInstanceOf(PrometheusService::class, $service1);
        $this->assertSame($service1, $service2);
    }

    public function test_metrics_provider_interface_resolves_to_prometheus_service(): void
    {
        IntegrationConfig::setValue('prometheus', 'endpoint', 'http://prometheus.local:9090');

        $this->app->forgetInstance(MetricsProviderInterface::class);
        $this->app->forgetInstance(PrometheusService::class);

        $service = $this->app->make(MetricsProviderInterface::class);

        $this->assertInstanceOf(PrometheusService::class, $service);
    }

    public function test_librenms_service_is_registered_as_singleton(): void
    {
        IntegrationConfig::setValue('librenms', 'endpoint', 'http://librenms.local');
        IntegrationConfig::setValue('librenms', 'api_key', 'test-token', true);

        $this->app->forgetInstance(LibreNmsService::class);

        $service1 = $this->app->make(LibreNmsService::class);
        $service2 = $this->app->make(LibreNmsService::class);

        $this->assertInstanceOf(LibreNmsService::class, $service1);
        $this->assertSame($service1, $service2);
    }

    // -------------------------------------------------------
    // 1. captive-portal / opnsense
    // -------------------------------------------------------

    public function test_captive_portal_returns_null_when_no_capability(): void
    {
        $service = $this->app->make(CaptivePortalInterface::class);

        $this->assertInstanceOf(NullCaptivePortal::class, $service);
    }

    public function test_captive_portal_returns_opnsense_when_capability_assigned(): void
    {
        IntegrationConfig::setValue('opnsense', 'endpoint', 'http://opnsense.local');
        IntegrationConfig::setValue('opnsense', 'key', 'key', true);
        IntegrationConfig::setValue('opnsense', 'secret', 'secret', true);
        IntegrationConfig::setValue('opnsense', 'zone_id', '1');
        CapabilityAssignment::assign('captive-portal', 'opnsense');

        $this->app->forgetInstance(OpnSenseClient::class);

        $service = $this->app->make(CaptivePortalInterface::class);

        $this->assertInstanceOf(OpnSenseCaptivePortal::class, $service);
    }

    // -------------------------------------------------------
    // 2. rate-limiting / opnsense
    // -------------------------------------------------------

    public function test_rate_limiting_returns_null_when_no_capability(): void
    {
        $service = $this->app->make(RateLimitingInterface::class);

        $this->assertInstanceOf(NullRateLimiter::class, $service);
    }

    public function test_rate_limiting_returns_opnsense_when_capability_assigned(): void
    {
        IntegrationConfig::setValue('opnsense', 'endpoint', 'http://opnsense.local');
        IntegrationConfig::setValue('opnsense', 'key', 'key', true);
        IntegrationConfig::setValue('opnsense', 'secret', 'secret', true);
        IntegrationConfig::setValue('opnsense', 'ratelimit_up_uuid', 'up-uuid');
        IntegrationConfig::setValue('opnsense', 'ratelimit_down_uuid', 'down-uuid');
        CapabilityAssignment::assign('rate-limiting', 'opnsense');

        $this->app->forgetInstance(OpnSenseClient::class);

        $service = $this->app->make(RateLimitingInterface::class);

        $this->assertInstanceOf(OpnSenseRateLimiter::class, $service);
    }

    // -------------------------------------------------------
    // 3. dhcp / opnsense
    // -------------------------------------------------------

    public function test_dhcp_returns_null_when_no_capability(): void
    {
        $service = $this->app->make(DhcpInterface::class);

        $this->assertInstanceOf(NullDhcpService::class, $service);
    }

    public function test_dhcp_returns_opnsense_when_capability_assigned(): void
    {
        IntegrationConfig::setValue('opnsense', 'endpoint', 'http://opnsense.local');
        IntegrationConfig::setValue('opnsense', 'key', 'key', true);
        IntegrationConfig::setValue('opnsense', 'secret', 'secret', true);
        IntegrationConfig::setValue('opnsense', 'dhcp_server', 'isc');
        CapabilityAssignment::assign('dhcp', 'opnsense');

        $service = $this->app->make(DhcpInterface::class);

        $this->assertInstanceOf(OpnSenseDhcpService::class, $service);
    }

    // -------------------------------------------------------
    // 4. dns-filtering / pihole
    // -------------------------------------------------------

    public function test_dns_filtering_returns_null_when_no_capability(): void
    {
        $service = $this->app->make(DnsFilteringInterface::class);

        $this->assertInstanceOf(NullDnsFiltering::class, $service);
    }

    public function test_dns_filtering_returns_pihole_when_capability_assigned(): void
    {
        IntegrationConfig::setValue('pihole', 'endpoint', 'http://pihole.local');
        IntegrationConfig::setValue('pihole', 'password', 'test-password', true);
        IntegrationConfig::setValue('pihole', 'filtered_group_id', '1');
        CapabilityAssignment::assign('dns-filtering', 'pihole');

        $service = $this->app->make(DnsFilteringInterface::class);

        $this->assertInstanceOf(PiHoleService::class, $service);
    }

    // -------------------------------------------------------
    // 5. ip-bandwidth / prometheus
    // -------------------------------------------------------

    public function test_ip_bandwidth_returns_null_when_no_capability(): void
    {
        $service = $this->app->make(IpBandwidthInterface::class);

        $this->assertInstanceOf(NullIpBandwidth::class, $service);
    }

    public function test_ip_bandwidth_returns_prometheus_when_capability_assigned(): void
    {
        IntegrationConfig::setValue('prometheus', 'endpoint', 'http://prometheus.local:9090');
        IntegrationConfig::setValue('prometheus', 'bearer_token', 'test-token');
        CapabilityAssignment::assign('ip-bandwidth', 'prometheus');

        $this->app->forgetInstance(PrometheusService::class);

        $service = $this->app->make(IpBandwidthInterface::class);

        $this->assertInstanceOf(PrometheusIpBandwidth::class, $service);
    }

    // -------------------------------------------------------
    // 6. port-bandwidth / prometheus
    // -------------------------------------------------------

    public function test_port_bandwidth_returns_null_when_no_capability(): void
    {
        $service = $this->app->make(PortBandwidthInterface::class);

        $this->assertInstanceOf(NullPortBandwidth::class, $service);
    }

    public function test_port_bandwidth_returns_prometheus_when_capability_assigned(): void
    {
        IntegrationConfig::setValue('prometheus', 'endpoint', 'http://prometheus.local:9090');
        IntegrationConfig::setValue('prometheus', 'bearer_token', 'test-token');
        CapabilityAssignment::assign('port-bandwidth', 'prometheus');

        $this->app->forgetInstance(PrometheusService::class);

        $service = $this->app->make(PortBandwidthInterface::class);

        $this->assertInstanceOf(PrometheusPortBandwidth::class, $service);
    }

    // -------------------------------------------------------
    // 7. port-errors / prometheus
    // -------------------------------------------------------

    public function test_port_errors_returns_null_when_no_capability(): void
    {
        $service = $this->app->make(PortErrorsInterface::class);

        $this->assertInstanceOf(NullPortErrors::class, $service);
    }

    public function test_port_errors_returns_prometheus_when_capability_assigned(): void
    {
        IntegrationConfig::setValue('prometheus', 'endpoint', 'http://prometheus.local:9090');
        IntegrationConfig::setValue('prometheus', 'bearer_token', 'test-token');
        CapabilityAssignment::assign('port-errors', 'prometheus');

        $this->app->forgetInstance(PrometheusService::class);

        $service = $this->app->make(PortErrorsInterface::class);

        $this->assertInstanceOf(PrometheusPortErrors::class, $service);
    }

    // -------------------------------------------------------
    // 8. ip-mac / librenms
    // -------------------------------------------------------

    public function test_ip_mac_returns_null_when_no_capability(): void
    {
        CapabilityAssignment::where('capability', 'ip-mac')->delete();

        $service = $this->app->make(IpMacResolverInterface::class);

        $this->assertInstanceOf(NullIpMacResolver::class, $service);
    }

    public function test_ip_mac_returns_librenms_when_capability_assigned(): void
    {
        IntegrationConfig::setValue('librenms', 'endpoint', 'http://librenms.local');
        IntegrationConfig::setValue('librenms', 'api_key', 'test-token', true);
        CapabilityAssignment::assign('ip-mac', 'librenms');

        $this->app->forgetInstance(LibreNmsService::class);

        $service = $this->app->make(IpMacResolverInterface::class);

        $this->assertInstanceOf(LibreNmsIpMacResolver::class, $service);
    }

    // -------------------------------------------------------
    // 9. port-mac / librenms
    // -------------------------------------------------------

    public function test_port_mac_returns_null_when_no_capability(): void
    {
        CapabilityAssignment::where('capability', 'port-mac')->delete();

        $service = $this->app->make(PortMacInterface::class);

        $this->assertInstanceOf(NullPortMac::class, $service);
    }

    public function test_port_mac_returns_librenms_when_capability_assigned(): void
    {
        IntegrationConfig::setValue('librenms', 'endpoint', 'http://librenms.local');
        IntegrationConfig::setValue('librenms', 'api_key', 'test-token', true);
        CapabilityAssignment::assign('port-mac', 'librenms');

        $this->app->forgetInstance(LibreNmsService::class);

        $service = $this->app->make(PortMacInterface::class);

        $this->assertInstanceOf(LibreNmsPortMac::class, $service);
    }

    // -------------------------------------------------------
    // Non-capability bindings
    // -------------------------------------------------------

    public function test_integration_tester_registry_is_registered(): void
    {
        $registry = $this->app->make(IntegrationTesterRegistry::class);

        $this->assertInstanceOf(IntegrationTesterRegistry::class, $registry);
    }

    public function test_opnsense_api_service_is_registered(): void
    {
        $service = $this->app->make(OpnSenseApiService::class);

        $this->assertInstanceOf(OpnSenseApiService::class, $service);
    }

    public function test_borealis_service_is_registered_as_singleton(): void
    {
        IntegrationConfig::setValue('borealis', 'endpoint', 'https://auth.test.local');
        IntegrationConfig::setValue('borealis', 'client_id', 'test-client-id');
        IntegrationConfig::setValue('borealis', 'client_secret', 'test-secret', true);

        $this->app->forgetInstance(BorealisService::class);

        $service1 = $this->app->make(BorealisService::class);
        $service2 = $this->app->make(BorealisService::class);

        $this->assertInstanceOf(BorealisService::class, $service1);
        $this->assertSame($service1, $service2);
    }

    public function test_network_inventory_interface_resolves_to_cached_service(): void
    {
        IntegrationConfig::setValue('librenms', 'endpoint', 'http://librenms.local');
        IntegrationConfig::setValue('librenms', 'api_key', 'test-token', true);

        $this->app->forgetInstance(NetworkInventoryInterface::class);
        $this->app->forgetInstance(LibreNmsService::class);

        $service = $this->app->make(NetworkInventoryInterface::class);

        $this->assertInstanceOf(CachedNetworkInventoryService::class, $service);
    }

    public function test_librenms_service_is_directly_resolvable(): void
    {
        IntegrationConfig::setValue('librenms', 'endpoint', 'http://librenms.local');
        IntegrationConfig::setValue('librenms', 'api_key', 'test-token', true);

        $this->app->forgetInstance(LibreNmsService::class);

        $service = $this->app->make(LibreNmsService::class);

        $this->assertInstanceOf(LibreNmsService::class, $service);
    }

    // -------------------------------------------------------
    // Edge cases: capability gate with DB errors
    // -------------------------------------------------------

    public function test_captive_portal_returns_null_when_different_integration_assigned(): void
    {
        // Assign a different integration for captive-portal
        CapabilityAssignment::assign('captive-portal', 'some-other');

        $service = $this->app->make(CaptivePortalInterface::class);

        $this->assertInstanceOf(NullCaptivePortal::class, $service);
    }

    public function test_capability_bindings_return_new_instance_on_each_resolve(): void
    {
        // Capability bindings use bind() not singleton(), so each resolution is fresh
        $service1 = $this->app->make(CaptivePortalInterface::class);
        $service2 = $this->app->make(CaptivePortalInterface::class);

        $this->assertInstanceOf(NullCaptivePortal::class, $service1);
        $this->assertInstanceOf(NullCaptivePortal::class, $service2);
        $this->assertNotSame($service1, $service2);
    }
}
