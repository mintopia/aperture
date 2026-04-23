<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\CapabilityAssignment;
use App\Models\IntegrationConfig;
use App\Services\BorealisService;
use App\Services\CachedNetworkInventoryService;
use App\Services\Dhcp\OpnSenseDhcpService;
use App\Services\Firewalls\OpnSense;
use App\Services\Firewalls\OpnSenseApiService;
use App\Services\Integration\BorealisTester;
use App\Services\Integration\IntegrationTesterRegistry;
use App\Services\Integration\LibreNmsTester;
use App\Services\Integration\NtopNgTester;
use App\Services\Integration\OpnSenseTester;
use App\Services\Integration\PiHoleTester;
use App\Services\Integration\PrometheusTester;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\DnsFilteringInterface;
use App\Services\Interfaces\FirewallBackendInterface;
use App\Services\Interfaces\MetricsProviderInterface;
use App\Services\Interfaces\NetworkInventoryInterface;
use App\Services\Interfaces\TrafficMonitorInterface;
use App\Services\LibreNmsService;
use App\Services\NtopNgService;
use App\Services\Null\NullDhcpService;
use App\Services\Null\NullMetricsProvider;
use App\Services\Null\NullNetworkInventoryService;
use App\Services\Null\NullTrafficMonitor;
use App\Services\PiHole\PiHoleService;
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
        $this->app->singleton(function (): IntegrationTesterRegistry {
            $registry = new IntegrationTesterRegistry;
            $registry->register('opnsense', new OpnSenseTester);
            $registry->register('pihole', new PiHoleTester);
            $registry->register('librenms', new LibreNmsTester);
            $registry->register('ntopng', new NtopNgTester);
            $registry->register('borealis', new BorealisTester);
            $registry->register('prometheus', new PrometheusTester);

            return $registry;
        });

        $this->app->singleton(function (): OpnSenseApiService {
            return new OpnSenseApiService($this->getIntegrationDbConfig('opnsense'));
        });

        $this->app->singleton(function (Application $app): FirewallBackendInterface {
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

        $this->app->singleton(function (Application $app): MetricsProviderInterface {
            $config = $this->getIntegrationDbConfig('prometheus');

            $endpoint = $config['endpoint'] ?? '';
            if ($endpoint === '' || ! ($config['enabled'] ?? false)) {
                return new NullMetricsProvider;
            }

            return new PrometheusService(
                endpoint: $endpoint,
                bearerToken: $config['bearer_token'] ?? '',
                verifySsl: (bool) ($config['verify_ssl'] ?? true),
                defaultStep: (int) ($config['default_step'] ?? 60),
            );
        });

        $this->app->singleton(function (): TrafficMonitorInterface {
            try {
                $isPrometheus = CapabilityAssignment::isActiveProvider('prometheus', 'user-bandwidth');
            } catch (Throwable) {
                $isPrometheus = false;
            }

            if (! $isPrometheus) {
                return new NullTrafficMonitor;
            }

            $config = $this->getIntegrationDbConfig('prometheus');
            $endpoint = $config['endpoint'] ?? '';
            if ($endpoint === '' || ! ($config['enabled'] ?? false)) {
                return new NullTrafficMonitor;
            }

            $prometheus = new PrometheusService(
                endpoint: $endpoint,
                bearerToken: $config['bearer_token'] ?? '',
                verifySsl: (bool) ($config['verify_ssl'] ?? true),
                defaultStep: (int) ($config['default_step'] ?? 60),
            );

            return new PrometheusTrafficMonitor(
                prometheus: $prometheus,
                rcvdMetric: $config['bandwidth_rcvd_metric'] ?? 'ntopng_host_bytes_rcvd',
                sentMetric: $config['bandwidth_sent_metric'] ?? 'ntopng_host_bytes_sent',
                ipLabel: $config['bandwidth_ip_label'] ?? 'ip',
            );
        });

        $this->app->singleton(function (Application $app): NtopNgService {
            $dbConfig = $this->getIntegrationDbConfig('ntopng');

            return new NtopNgService(
                endpoint: (string) ($dbConfig['endpoint'] ?? ''),
                username: (string) ($dbConfig['username'] ?? ''),
                password: (string) ($dbConfig['password'] ?? ''),
                interface: (int) ($dbConfig['interface'] ?? 0),
            );
        });

        $this->app->singleton(function (Application $app): BorealisService {
            $dbConfig = $this->getIntegrationDbConfig('borealis');

            return new BorealisService(
                clientId: (string) ($dbConfig['client_id'] ?? ''),
                clientSecret: (string) ($dbConfig['client_secret'] ?? ''),
                endpoint: (string) ($dbConfig['endpoint'] ?? ''),
            );
        });

        $this->app->singleton(function (Application $app): NetworkInventoryInterface {
            $dbConfig = $this->getIntegrationDbConfig('librenms');

            $endpoint = (string) ($dbConfig['endpoint'] ?? '');
            if ($endpoint === '' || ! ($dbConfig['enabled'] ?? false)) {
                return new NullNetworkInventoryService;
            }

            $inner = new LibreNmsService(
                endpoint: $endpoint,
                apiToken: (string) ($dbConfig['api_key'] ?? ''),
            );

            return new CachedNetworkInventoryService(
                $inner,
                $app->make('cache.store'),
            );
        });

        $this->app->singleton(function (Application $app): DhcpInterface {
            $opnsenseConfig = $this->getIntegrationDbConfig('opnsense');
            $dhcpServer = (string) ($opnsenseConfig['dhcp_server'] ?? '');

            if ($dhcpServer === '') {
                return new NullDhcpService;
            }

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
        });

        $this->app->singleton(function (Application $app): DnsFilteringInterface {
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
        });
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
