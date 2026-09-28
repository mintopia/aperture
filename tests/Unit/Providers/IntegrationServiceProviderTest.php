<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Integration\CapabilityResolver;
use App\Integration\CiscoBootstrapper;
use App\Integration\InstallGuard;
use App\Integration\KeaBootstrapper;
use App\Integration\LibreNmsBootstrapper;
use App\Integration\OpnSenseBootstrapper;
use App\Integration\PiHoleBootstrapper;
use App\Integration\PrometheusBootstrapper;
use App\Integration\VyOsBootstrapper;
use App\Models\CapabilityAssignment;
use App\Models\IntegrationConfig;
use App\Services\BorealisService;
use App\Services\Firewalls\OpnSenseApiService;
use App\Services\Integration\IntegrationTesterRegistry;
use App\Services\Interfaces\CaptivePortalInterface;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\DnsFilteringInterface;
use App\Services\Interfaces\IpBandwidthInterface;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Interfaces\PortBandwidthInterface;
use App\Services\Interfaces\PortErrorsInterface;
use App\Services\Interfaces\PortMacInterface;
use App\Services\Interfaces\RateLimitingInterface;
use App\Services\Kea\KeaDhcpService;
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
use App\Services\VyOs\VyOsIpMacResolver;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\TestCase;

class IntegrationServiceProviderTest extends TestCase
{
    use LazilyRefreshDatabase;

    // -------------------------------------------------------
    // Shared singletons
    // -------------------------------------------------------

    public static function singletonBindingProvider(): array
    {
        return [
            'opnsense client' => [
                function (): void {
                    IntegrationConfig::setValue('opnsense', 'endpoint', 'http://opnsense.local');
                    IntegrationConfig::setValue('opnsense', 'key', 'test-key', true);
                    IntegrationConfig::setValue('opnsense', 'secret', 'test-secret', true);
                },
                OpnSenseClient::class,
            ],
            'prometheus service' => [
                function (): void {
                    IntegrationConfig::setValue('prometheus', 'endpoint', 'http://prometheus.local:9090');
                    IntegrationConfig::setValue('prometheus', 'bearer_token', 'test-token');
                },
                PrometheusService::class,
            ],
            'librenms service' => [
                function (): void {
                    IntegrationConfig::setValue('librenms', 'endpoint', 'http://librenms.local');
                    IntegrationConfig::setValue('librenms', 'api_key', 'test-token', true);
                },
                LibreNmsService::class,
            ],
            'borealis service' => [
                function (): void {
                    IntegrationConfig::setValue('borealis', 'endpoint', 'https://auth.test.local');
                    IntegrationConfig::setValue('borealis', 'client_id', 'test-client-id');
                    IntegrationConfig::setValue('borealis', 'client_secret', 'test-secret', true);
                },
                BorealisService::class,
            ],
        ];
    }

    #[DataProvider('singletonBindingProvider')]
    public function test_service_is_scoped_per_request(Closure $configureIntegration, string $class): void
    {
        $configureIntegration();
        $this->app->forgetInstance($class);

        $instance1 = $this->app->make($class);
        $instance2 = $this->app->make($class);

        $this->assertInstanceOf($class, $instance1);
        $this->assertSame($instance1, $instance2);

        $this->app->forgetScopedInstances();
        $this->assertNotSame($instance1, $this->app->make($class));
    }

    // -------------------------------------------------------
    // Capability -> interface bindings: falls back to Null* when unassigned
    // -------------------------------------------------------

    public static function nullFallbackProvider(): array
    {
        $noSetup = function (): void {};

        return [
            'captive-portal' => [$noSetup, CaptivePortalInterface::class, NullCaptivePortal::class],
            'rate-limiting' => [$noSetup, RateLimitingInterface::class, NullRateLimiter::class],
            'dhcp' => [$noSetup, DhcpInterface::class, NullDhcpService::class],
            'dns-filtering' => [$noSetup, DnsFilteringInterface::class, NullDnsFiltering::class],
            'ip-bandwidth' => [$noSetup, IpBandwidthInterface::class, NullIpBandwidth::class],
            'port-bandwidth' => [$noSetup, PortBandwidthInterface::class, NullPortBandwidth::class],
            'port-errors' => [$noSetup, PortErrorsInterface::class, NullPortErrors::class],
            'ip-mac' => [
                function (): void {
                    CapabilityAssignment::where('capability', 'ip-mac')->delete();
                },
                IpMacResolverInterface::class,
                NullIpMacResolver::class,
            ],
            'port-mac' => [
                function (): void {
                    CapabilityAssignment::where('capability', 'port-mac')->delete();
                },
                PortMacInterface::class,
                NullPortMac::class,
            ],
        ];
    }

    #[DataProvider('nullFallbackProvider')]
    public function test_returns_null_service_when_no_capability_assigned(Closure $setup, string $interface, string $expectedNullClass): void
    {
        $setup();

        $service = $this->app->make($interface);

        $this->assertInstanceOf($expectedNullClass, $service);
    }

    // -------------------------------------------------------
    // Capability -> interface bindings: resolves the assigned integration
    // -------------------------------------------------------

    public static function capabilityAssignedProvider(): array
    {
        return [
            'captive-portal -> opnsense' => [
                function (): void {
                    IntegrationConfig::setValue('opnsense', 'endpoint', 'http://opnsense.local');
                    IntegrationConfig::setValue('opnsense', 'key', 'key', true);
                    IntegrationConfig::setValue('opnsense', 'secret', 'secret', true);
                    IntegrationConfig::setValue('opnsense', 'zone_id', '1');
                    CapabilityAssignment::assign('captive-portal', 'opnsense');
                    app()->forgetInstance(OpnSenseClient::class);
                },
                CaptivePortalInterface::class,
                OpnSenseCaptivePortal::class,
            ],
            'rate-limiting -> opnsense' => [
                function (): void {
                    IntegrationConfig::setValue('opnsense', 'endpoint', 'http://opnsense.local');
                    IntegrationConfig::setValue('opnsense', 'key', 'key', true);
                    IntegrationConfig::setValue('opnsense', 'secret', 'secret', true);
                    IntegrationConfig::setValue('opnsense', 'ratelimit_up_uuid', 'up-uuid');
                    IntegrationConfig::setValue('opnsense', 'ratelimit_down_uuid', 'down-uuid');
                    CapabilityAssignment::assign('rate-limiting', 'opnsense');
                    app()->forgetInstance(OpnSenseClient::class);
                },
                RateLimitingInterface::class,
                OpnSenseRateLimiter::class,
            ],
            'dhcp -> opnsense' => [
                function (): void {
                    IntegrationConfig::setValue('opnsense', 'endpoint', 'http://opnsense.local');
                    IntegrationConfig::setValue('opnsense', 'key', 'key', true);
                    IntegrationConfig::setValue('opnsense', 'secret', 'secret', true);
                    IntegrationConfig::setValue('opnsense', 'dhcp_server', 'isc');
                    CapabilityAssignment::assign('dhcp', 'opnsense');
                },
                DhcpInterface::class,
                OpnSenseDhcpService::class,
            ],
            'dns-filtering -> pihole' => [
                function (): void {
                    IntegrationConfig::setValue('pihole', 'endpoint', 'http://pihole.local');
                    IntegrationConfig::setValue('pihole', 'password', 'test-password', true);
                    IntegrationConfig::setValue('pihole', 'filtered_group_id', '1');
                    CapabilityAssignment::assign('dns-filtering', 'pihole');
                },
                DnsFilteringInterface::class,
                PiHoleService::class,
            ],
            'ip-bandwidth -> prometheus' => [
                function (): void {
                    IntegrationConfig::setValue('prometheus', 'endpoint', 'http://prometheus.local:9090');
                    IntegrationConfig::setValue('prometheus', 'bearer_token', 'test-token');
                    CapabilityAssignment::assign('ip-bandwidth', 'prometheus');
                    app()->forgetInstance(PrometheusService::class);
                },
                IpBandwidthInterface::class,
                PrometheusIpBandwidth::class,
            ],
            'port-bandwidth -> prometheus' => [
                function (): void {
                    IntegrationConfig::setValue('prometheus', 'endpoint', 'http://prometheus.local:9090');
                    IntegrationConfig::setValue('prometheus', 'bearer_token', 'test-token');
                    CapabilityAssignment::assign('port-bandwidth', 'prometheus');
                    app()->forgetInstance(PrometheusService::class);
                },
                PortBandwidthInterface::class,
                PrometheusPortBandwidth::class,
            ],
            'port-errors -> prometheus' => [
                function (): void {
                    IntegrationConfig::setValue('prometheus', 'endpoint', 'http://prometheus.local:9090');
                    IntegrationConfig::setValue('prometheus', 'bearer_token', 'test-token');
                    CapabilityAssignment::assign('port-errors', 'prometheus');
                    app()->forgetInstance(PrometheusService::class);
                },
                PortErrorsInterface::class,
                PrometheusPortErrors::class,
            ],
            'ip-mac -> librenms' => [
                function (): void {
                    IntegrationConfig::setValue('librenms', 'endpoint', 'http://librenms.local');
                    IntegrationConfig::setValue('librenms', 'api_key', 'test-token', true);
                    CapabilityAssignment::assign('ip-mac', 'librenms');
                    app()->forgetInstance(LibreNmsService::class);
                },
                IpMacResolverInterface::class,
                LibreNmsIpMacResolver::class,
            ],
            'port-mac -> librenms' => [
                function (): void {
                    IntegrationConfig::setValue('librenms', 'endpoint', 'http://librenms.local');
                    IntegrationConfig::setValue('librenms', 'api_key', 'test-token', true);
                    CapabilityAssignment::assign('port-mac', 'librenms');
                    app()->forgetInstance(LibreNmsService::class);
                },
                PortMacInterface::class,
                LibreNmsPortMac::class,
            ],
        ];
    }

    #[DataProvider('capabilityAssignedProvider')]
    public function test_returns_assigned_service_when_capability_assigned(Closure $setup, string $interface, string $expectedClass): void
    {
        $setup();

        $service = $this->app->make($interface);

        $this->assertInstanceOf($expectedClass, $service);
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

    public function test_resolution_does_not_depend_on_bootstrapper_registration_order(): void
    {
        IntegrationConfig::setValue('kea', 'endpoint_v4', 'https://kea.local');
        IntegrationConfig::setValue('opnsense', 'endpoint', 'http://opnsense.local');
        CapabilityAssignment::assign('dhcp', 'kea');
        CapabilityAssignment::assign('ip-mac', 'vyos');

        $bootstrappers = [
            new OpnSenseBootstrapper, new PrometheusBootstrapper, new LibreNmsBootstrapper,
            new PiHoleBootstrapper, new VyOsBootstrapper, new CiscoBootstrapper, new KeaBootstrapper,
        ];

        $forward = new CapabilityResolver($this->app, $bootstrappers);
        $reversed = new CapabilityResolver($this->app, array_reverse($bootstrappers));

        foreach (array_keys(CapabilityResolver::contracts()) as $capability) {
            $this->assertSame($forward->resolve($capability)::class, $reversed->resolve($capability)::class, $capability);
        }

        $this->assertInstanceOf(KeaDhcpService::class, $reversed->resolve('dhcp'));
        $this->assertInstanceOf(VyOsIpMacResolver::class, $reversed->resolve('ip-mac'));
    }

    public function test_unusable_active_provider_falls_back_to_null_implementation(): void
    {
        CapabilityAssignment::assign('dhcp', 'kea');

        $this->assertInstanceOf(NullDhcpService::class, $this->app->make(DhcpInterface::class));
    }

    public function test_reassigning_capability_is_picked_up_within_same_scope(): void
    {
        $this->assertInstanceOf(NullDnsFiltering::class, $this->app->make(DnsFilteringInterface::class));

        CapabilityAssignment::assign('dns-filtering', 'pihole');

        $this->assertInstanceOf(PiHoleService::class, $this->app->make(DnsFilteringInterface::class));
    }

    public function test_saving_integration_config_yields_client_with_new_values(): void
    {
        IntegrationConfig::setValue('prometheus', 'default_step', '30');
        $first = $this->app->make(PrometheusService::class);

        IntegrationConfig::setValue('prometheus', 'default_step', '90');
        $second = $this->app->make(PrometheusService::class);

        $this->assertNotSame($first, $second);
        $this->assertSame(30, (new ReflectionProperty($first, 'defaultStep'))->getValue($first));
        $this->assertSame(90, (new ReflectionProperty($second, 'defaultStep'))->getValue($second));
    }

    public function test_missing_capability_table_is_tolerated_but_other_query_errors_propagate(): void
    {
        Schema::drop('capability_assignments');
        $this->assertInstanceOf(NullDhcpService::class, $this->app->make(DhcpInterface::class));

        $this->expectException(QueryException::class);
        InstallGuard::tolerateMissingTable(fn (): mixed => DB::select('select * from'), null);
    }
}
