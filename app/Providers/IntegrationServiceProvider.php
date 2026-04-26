<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\CapabilityAssignment;
use App\Models\IntegrationConfig;
use App\Services\BorealisService;
use App\Services\CachedNetworkInventoryService;
use App\Services\Firewalls\OpnSense;
use App\Services\Firewalls\OpnSenseApiService;
use App\Services\Integration\BorealisTester;
use App\Services\Integration\IntegrationTesterRegistry;
use App\Services\Integration\LibreNmsTester;
use App\Services\Integration\NtopNgTester;
use App\Services\Integration\OpnSenseTester;
use App\Services\Integration\PiHoleTester;
use App\Services\Integration\PrometheusTester;
use App\Services\Interfaces\CaptivePortalInterface;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\DnsFilteringInterface;
use App\Services\Interfaces\FirewallBackendInterface;
use App\Services\Interfaces\HostStatsProviderInterface;
use App\Services\Interfaces\IpBandwidthInterface;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Interfaces\MetricsProviderInterface;
use App\Services\Interfaces\NetworkInventoryInterface;
use App\Services\Interfaces\PortBandwidthInterface;
use App\Services\Interfaces\PortErrorsInterface;
use App\Services\Interfaces\PortMacInterface;
use App\Services\Interfaces\RateLimitingInterface;
use App\Services\Interfaces\TrafficMonitorInterface;
use App\Services\LibreNms\LibreNmsIpMacResolver;
use App\Services\LibreNms\LibreNmsPortMac;
use App\Services\LibreNms\LibreNmsService;
use App\Services\NtopNgService;
use App\Services\Null\NullCaptivePortal;
use App\Services\Null\NullDhcpService;
use App\Services\Null\NullDnsFiltering;
use App\Services\Null\NullHostStatsProvider;
use App\Services\Null\NullIpBandwidth;
use App\Services\Null\NullIpMacResolver;
use App\Services\Null\NullPortBandwidth;
use App\Services\Null\NullPortErrors;
use App\Services\Null\NullPortMac;
use App\Services\Null\NullRateLimiter;
use App\Services\Null\NullTrafficMonitor;
use App\Services\OpnSense\OpnSenseCaptivePortal;
use App\Services\OpnSense\OpnSenseClient;
use App\Services\OpnSense\OpnSenseDhcpService;
use App\Services\OpnSense\OpnSenseRateLimiter;
use App\Services\PiHole\PiHoleService;
use App\Services\Prometheus\PrometheusIpBandwidth;
use App\Services\Prometheus\PrometheusPortBandwidth;
use App\Services\Prometheus\PrometheusPortErrors;
use App\Services\Prometheus\PrometheusService;
use App\Services\Prometheus\PrometheusTrafficMonitor;
use GuzzleHttp\Client;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Throwable;

class IntegrationServiceProvider extends ServiceProvider
{
    /**
     * Register external service bindings.
     */
    public function register(): void
    {
        $this->registerIntegrationTesters();
        $this->registerSharedSingletons();
        $this->registerCapabilityBindings();
        $this->registerNonCapabilityBindings();
    }

    /**
     * Register the integration tester registry.
     */
    protected function registerIntegrationTesters(): void
    {
        $this->app->singleton(IntegrationTesterRegistry::class, function (): IntegrationTesterRegistry {
            $registry = new IntegrationTesterRegistry;
            $registry->register('opnsense', new OpnSenseTester);
            $registry->register('pihole', new PiHoleTester);
            $registry->register('librenms', new LibreNmsTester);
            $registry->register('ntopng', new NtopNgTester);
            $registry->register('borealis', new BorealisTester);
            $registry->register('prometheus', new PrometheusTester);

            return $registry;
        });
    }

    /**
     * Register shared singletons used by multiple capability bindings.
     */
    protected function registerSharedSingletons(): void
    {
        $this->app->singleton(OpnSenseClient::class, function (): OpnSenseClient {
            $dbConfig = $this->getIntegrationDbConfig('opnsense');

            $client = new Client([
                'verify' => (bool) ($dbConfig['verify_ssl'] ?? true),
                'base_uri' => $dbConfig['endpoint'] ?? '',
                'auth' => [$dbConfig['key'] ?? '', $dbConfig['secret'] ?? ''],
            ]);

            return new OpnSenseClient($client);
        });

        $this->app->singleton(PrometheusService::class, function (): PrometheusService {
            $config = $this->getIntegrationDbConfig('prometheus');

            return new PrometheusService(
                endpoint: (string) ($config['endpoint'] ?? ''),
                bearerToken: (string) ($config['bearer_token'] ?? ''),
                verifySsl: (bool) ($config['verify_ssl'] ?? true),
                defaultStep: (int) ($config['default_step'] ?? 60),
            );
        });

        $this->app->singleton(MetricsProviderInterface::class, function (Application $app): MetricsProviderInterface {
            return $app->make(PrometheusService::class);
        });

        $this->app->singleton(LibreNmsService::class, function (): LibreNmsService {
            $dbConfig = $this->getIntegrationDbConfig('librenms');

            return new LibreNmsService(
                endpoint: (string) ($dbConfig['endpoint'] ?? ''),
                apiToken: (string) ($dbConfig['api_key'] ?? ''),
            );
        });
    }

    /**
     * Register all 9 capability-gated bindings.
     */
    protected function registerCapabilityBindings(): void
    {
        // 1. captive-portal / opnsense
        $this->app->bind(CaptivePortalInterface::class, function (Application $app): CaptivePortalInterface {
            if ($this->isActive('opnsense', 'captive-portal')) {
                return new OpnSenseCaptivePortal(
                    $app->make(OpnSenseClient::class),
                    (int) IntegrationConfig::getValue('opnsense', 'zone_id', '0'),
                );
            }

            return new NullCaptivePortal;
        });

        // 2. rate-limiting / opnsense
        $this->app->bind(RateLimitingInterface::class, function (Application $app): RateLimitingInterface {
            if ($this->isActive('opnsense', 'rate-limiting')) {
                return new OpnSenseRateLimiter(
                    $app->make(OpnSenseClient::class),
                    (string) IntegrationConfig::getValue('opnsense', 'ratelimit_up_uuid', ''),
                    (string) IntegrationConfig::getValue('opnsense', 'ratelimit_down_uuid', ''),
                );
            }

            return new NullRateLimiter;
        });

        // 3. dhcp / opnsense
        $this->app->bind(DhcpInterface::class, function (Application $app): DhcpInterface {
            if ($this->isActive('opnsense', 'dhcp')) {
                return $this->buildDhcpService($app);
            }

            return new NullDhcpService;
        });

        // 4. dns-filtering / pihole
        $this->app->bind(DnsFilteringInterface::class, function (): DnsFilteringInterface {
            if ($this->isActive('pihole', 'dns-filtering')) {
                $dbConfig = $this->getIntegrationDbConfig('pihole');
                $client = new Client([
                    'verify' => (bool) ($dbConfig['verify_ssl'] ?? true),
                    'base_uri' => $dbConfig['endpoint'] ?? '',
                ]);

                return new PiHoleService(
                    $client,
                    (string) ($dbConfig['password'] ?? ''),
                    (int) ($dbConfig['filtered_group_id'] ?? 1),
                );
            }

            return new NullDnsFiltering;
        });

        // 5. ip-bandwidth / prometheus
        $this->app->bind(IpBandwidthInterface::class, function (Application $app): IpBandwidthInterface {
            if ($this->isActive('prometheus', 'ip-bandwidth')) {
                $config = $this->getIntegrationDbConfig('prometheus');

                return new PrometheusIpBandwidth(
                    $app->make(PrometheusService::class),
                    (string) ($config['bandwidth_rcvd_metric'] ?? 'ntopng_host_bytes_rcvd'),
                    (string) ($config['bandwidth_sent_metric'] ?? 'ntopng_host_bytes_sent'),
                    (string) ($config['bandwidth_ip_label'] ?? 'ip'),
                );
            }

            return new NullIpBandwidth;
        });

        // 6. port-bandwidth / prometheus
        $this->app->bind(PortBandwidthInterface::class, function (Application $app): PortBandwidthInterface {
            if ($this->isActive('prometheus', 'port-bandwidth')) {
                return new PrometheusPortBandwidth(
                    $app->make(PrometheusService::class),
                );
            }

            return new NullPortBandwidth;
        });

        // 7. port-errors / prometheus
        $this->app->bind(PortErrorsInterface::class, function (Application $app): PortErrorsInterface {
            if ($this->isActive('prometheus', 'port-errors')) {
                return new PrometheusPortErrors(
                    $app->make(PrometheusService::class),
                );
            }

            return new NullPortErrors;
        });

        // 8. ip-mac / librenms
        $this->app->bind(IpMacResolverInterface::class, function (Application $app): IpMacResolverInterface {
            if ($this->isActive('librenms', 'ip-mac')) {
                return new LibreNmsIpMacResolver(
                    $app->make(LibreNmsService::class),
                );
            }

            return new NullIpMacResolver;
        });

        // 9. port-mac / librenms
        $this->app->bind(PortMacInterface::class, function (Application $app): PortMacInterface {
            if ($this->isActive('librenms', 'port-mac')) {
                return new LibreNmsPortMac(
                    $app->make(LibreNmsService::class),
                );
            }

            return new NullPortMac;
        });
    }

    /**
     * Register non-capability bindings (BorealisService, NetworkInventoryInterface, etc.).
     */
    protected function registerNonCapabilityBindings(): void
    {
        $this->app->singleton(OpnSenseApiService::class, function (): OpnSenseApiService {
            return new OpnSenseApiService($this->getIntegrationDbConfig('opnsense'));
        });

        $this->app->singleton(BorealisService::class, function (): BorealisService {
            $dbConfig = $this->getIntegrationDbConfig('borealis');

            return new BorealisService(
                clientId: (string) ($dbConfig['client_id'] ?? ''),
                clientSecret: (string) ($dbConfig['client_secret'] ?? ''),
                endpoint: (string) ($dbConfig['endpoint'] ?? ''),
            );
        });

        $this->app->singleton(NetworkInventoryInterface::class, function (Application $app): NetworkInventoryInterface {
            $libreNms = $app->make(LibreNmsService::class);

            return new CachedNetworkInventoryService(
                $libreNms,
                $app->make('cache.store'),
            );
        });

        $this->registerLegacyBindings();
    }

    /**
     * Legacy bindings for interfaces still consumed by existing code.
     * These will be removed as consumers are migrated to capability-specific interfaces.
     */
    protected function registerLegacyBindings(): void
    {
        $this->app->singleton(FirewallBackendInterface::class, function (Application $app): FirewallBackendInterface {
            $dbConfig = $this->getIntegrationDbConfig('opnsense');

            $client = new Client([
                'verify' => (bool) ($dbConfig['verify_ssl'] ?? true),
                'base_uri' => $dbConfig['endpoint'] ?? '',
                'auth' => [$dbConfig['key'] ?? '', $dbConfig['secret'] ?? ''],
            ]);

            return new OpnSense(
                client: $client,
                zoneId: (int) ($dbConfig['zone_id'] ?? 0),
                uploadRuleUuid: (string) ($dbConfig['ratelimit_up_uuid'] ?? ''),
                downloadRuleUuid: (string) ($dbConfig['ratelimit_down_uuid'] ?? ''),
            );
        });

        $this->app->singleton(TrafficMonitorInterface::class, function (): TrafficMonitorInterface {
            if (! $this->isActive('prometheus', 'user-bandwidth')) {
                return new NullTrafficMonitor;
            }

            $config = $this->getIntegrationDbConfig('prometheus');
            $endpoint = $config['endpoint'] ?? '';
            if ($endpoint === '' || ! ($config['enabled'] ?? false)) {
                return new NullTrafficMonitor;
            }

            $prometheus = new PrometheusService(
                endpoint: $endpoint,
                bearerToken: (string) ($config['bearer_token'] ?? ''),
                verifySsl: (bool) ($config['verify_ssl'] ?? true),
                defaultStep: (int) ($config['default_step'] ?? 60),
            );

            return new PrometheusTrafficMonitor(
                prometheus: $prometheus,
                rcvdMetric: (string) ($config['bandwidth_rcvd_metric'] ?? 'ntopng_host_bytes_rcvd'),
                sentMetric: (string) ($config['bandwidth_sent_metric'] ?? 'ntopng_host_bytes_sent'),
                ipLabel: (string) ($config['bandwidth_ip_label'] ?? 'ip'),
            );
        });

        $this->app->singleton(HostStatsProviderInterface::class, function (): HostStatsProviderInterface {
            if (! $this->isActive('ntopng', 'host-stats')) {
                return new NullHostStatsProvider;
            }

            $dbConfig = $this->getIntegrationDbConfig('ntopng');

            return new NtopNgService(
                endpoint: (string) ($dbConfig['endpoint'] ?? ''),
                username: (string) ($dbConfig['username'] ?? ''),
                password: (string) ($dbConfig['password'] ?? ''),
                interface: (int) ($dbConfig['interface'] ?? 0),
            );
        });
    }

    /**
     * Check if an integration is the active provider for a capability, safely handling DB errors.
     */
    protected function isActive(string $integration, string $capability): bool
    {
        try {
            return CapabilityAssignment::isActiveProvider($integration, $capability);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Build the OpnSense DHCP service with all necessary configuration.
     */
    protected function buildDhcpService(Application $app): OpnSenseDhcpService
    {
        $opnsenseConfig = $this->getIntegrationDbConfig('opnsense');
        $dhcpServer = (string) ($opnsenseConfig['dhcp_server'] ?? 'isc');

        $paths = match ($dhcpServer) {
            'kea' => [
                'leases' => '/api/kea/leases/search',
                'ipv4_ranges' => '/api/kea/dhcpv4/search_subnet',
                'ipv6_ranges' => '/api/kea/dhcpv6/search_subnet',
            ],
            'dnsmasq' => [
                'leases' => '/api/dnsmasq/leases/search',
                'ipv4_ranges' => '/api/dnsmasq/settings/search_range',
                'ipv6_ranges' => '/api/dnsmasq/settings/search_range',
            ],
            default => [
                'leases' => '/api/dhcpv4/leases/search_lease',
                'ipv4_ranges' => '',
                'ipv6_ranges' => '/api/dhcpv6/leases/search_lease',
            ],
        };

        $leaseFieldMap = match ($dhcpServer) {
            'kea' => [
                'ip' => 'address',
                'mac' => 'hwaddr',
                'hostname' => 'hostname',
                'expires' => 'expire',
                'status' => 'state',
            ],
            'dnsmasq' => [
                'ip' => 'address',
                'mac' => 'hwaddr',
                'hostname' => 'hostname',
                'expires' => 'expire',
                'status' => 'status',
            ],
            default => [
                'ip' => 'address',
                'mac' => 'mac',
                'hostname' => 'hostname',
                'expires' => 'ends',
                'status' => 'status',
            ],
        };

        $rangeFieldMap = match ($dhcpServer) {
            'kea' => [
                'interface' => 'interface',
                'subnet' => 'subnet',
                'range_from' => 'range_from',
                'range_to' => 'range_to',
                'gateway' => 'option_data.routers',
                'description' => 'description',
                'prefix' => 'prefix',
                'pools' => 'pools',
            ],
            'dnsmasq' => [
                'interface' => 'interface',
                'subnet' => 'subnet',
                'range_from' => 'start_addr',
                'range_to' => 'end_addr',
                'gateway' => 'gateway',
                'description' => '%set_tag',
                'prefix' => 'prefix_len',
                'subnet_mask' => 'subnet_mask',
            ],
            default => [
                'interface' => 'interface',
                'subnet' => 'subnet',
                'range_from' => 'range_from',
                'range_to' => 'range_to',
                'gateway' => 'gateway',
                'description' => 'description',
                'prefix' => 'prefix',
            ],
        };

        $client = new Client([
            'verify' => (bool) ($opnsenseConfig['verify_ssl'] ?? true),
            'base_uri' => $opnsenseConfig['endpoint'] ?? '',
            'auth' => [
                $opnsenseConfig['key'] ?? '',
                $opnsenseConfig['secret'] ?? '',
            ],
        ]);

        return new OpnSenseDhcpService(
            $client,
            (int) ($opnsenseConfig['pool_size'] ?? 254),
            $paths['leases'],
            $paths['ipv4_ranges'],
            $paths['ipv6_ranges'],
            $leaseFieldMap,
            $rangeFieldMap,
            $dhcpServer === 'kea',
        );
    }

    /**
     * Safely load integration config from DB, returning empty array if table doesn't exist.
     *
     * @return array<string, mixed>
     */
    protected function getIntegrationDbConfig(string $integration): array
    {
        try {
            return IntegrationConfig::getAll($integration);
        } catch (Throwable) {
            return [];
        }
    }
}
