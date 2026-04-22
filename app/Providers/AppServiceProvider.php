<?php

namespace App\Providers;

use App\Models\CapabilityAssignment;
use App\Models\IntegrationConfig;
use App\Models\IpAddress;
use App\Models\Setting;
use App\Models\SwitchConfig;
use App\Models\User;
use App\Models\UserIpAddress;
use App\Observers\IpAddressObserver;
use App\Observers\UserIpAddressObserver;
use App\Observers\UserObserver;
use App\Services\Auth\BorealisDeviceFlowService;
use App\Services\BorealisService;
use App\Services\CachedNetworkInventoryService;
use App\Services\Dhcp\OpnSenseDhcpService;
use App\Services\Firewalls\OpnSense;
use App\Services\Integration\BorealisTester;
use App\Services\Integration\IntegrationTesterRegistry;
use App\Services\Integration\LibreNmsTester;
use App\Services\Integration\NtopNgTester;
use App\Services\Integration\OpnSenseTester;
use App\Services\Integration\PiHoleTester;
use App\Services\Integration\PrometheusTester;
use App\Services\Interfaces\AuthProviderInterface;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\DnsFilteringInterface;
use App\Services\Interfaces\FirewallBackendInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\Interfaces\MetricsProviderInterface;
use App\Services\Interfaces\NetworkInventoryInterface;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\Interfaces\SshProxyClientInterface;
use App\Services\Interfaces\TrafficMonitorInterface;
use App\Services\LibreNmsService;
use App\Services\MacAddressResolver;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use App\Services\NtopNgService;
use App\Services\Null\NullDhcpService;
use App\Services\Null\NullMetricsProvider;
use App\Services\Null\NullTrafficMonitor;
use App\Services\PiHole\PiHoleService;
use App\Services\Prometheus\PrometheusService;
use App\Services\Prometheus\PrometheusTrafficMonitor;
use App\Services\SshProxy\SshProxyClient;
use GuzzleHttp\Client;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AuthProviderInterface::class, BorealisDeviceFlowService::class);

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

        $this->app->singleton(function (Application $app): FirewallBackendInterface {
            $dbConfig = $this->getIntegrationDbConfig('opnsense');

            return new OpnSense(
                endpoint: (string) ($dbConfig['endpoint'] ?? ''),
                key: (string) ($dbConfig['key'] ?? ''),
                secret: (string) ($dbConfig['secret'] ?? ''),
                zoneId: (int) ($dbConfig['zone_id'] ?? 0),
                verify: (bool) ($dbConfig['verify_ssl'] ?? true),
                uploadRuleUuid: (string) ($dbConfig['ratelimit_up_uuid'] ?? ''),
                downloadRuleUuid: (string) ($dbConfig['ratelimit_down_uuid'] ?? ''),
            );
        });

        $this->app->singleton(function (Application $app): SshProxyClientInterface {
            $host = (string) config('aperture.ssh_proxy.host');
            $isIpAddress = filter_var($host, FILTER_VALIDATE_IP) !== false;
            $isBracketedIpv6 = preg_match('/^\[(.+)]$/', $host, $matches) === 1
                && filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            $isHostname = filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;

            if (
                $host === ''
                ||
                str_contains($host, '://')
                || str_contains($host, '/')
                || str_contains($host, '?')
                || str_contains($host, '#')
                || str_contains($host, '@')
                || preg_match('/\s/', $host) === 1
                || (! $isIpAddress && ! $isBracketedIpv6 && ! $isHostname)
            ) {
                throw new RuntimeException('Invalid SSH proxy host configuration [aperture.ssh_proxy.host]. Use a plain hostname or IP without scheme/path.');
            }

            $urlHost = $host;
            if ($isIpAddress && str_contains($host, ':') && ! str_starts_with($host, '[')) {
                $urlHost = sprintf('[%s]', $host);
            }

            return new SshProxyClient(
                sprintf('http://%s:%d', $urlHost, config('aperture.ssh_proxy.port')),
                (string) config('aperture.ssh_proxy.api_key'),
                timeout: (int) config('aperture.ssh_proxy.request_timeout', 60),
                connectTimeout: (int) config('aperture.ssh_proxy.connect_timeout', 5),
            );
        });

        $this->app->singleton(function (Application $app): SwitchServiceFactory {
            return new SwitchServiceFactory(
                proxyClient: config('aperture.ssh_proxy.enabled', false) ? $app->make(SshProxyClientInterface::class) : null,
                proxyEnabled: (bool) config('aperture.ssh_proxy.enabled', false),
            );
        });

        $this->app->singleton(function (): MetricsProviderInterface {
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        User::observe(UserObserver::class);
        IpAddress::observe(IpAddressObserver::class);
        UserIpAddress::observe(UserIpAddressObserver::class);

        try {
            $siteTitle = (string) Setting::get('site_title', config('app.name', 'Aperture'));
        } catch (Throwable) {
            $siteTitle = (string) config('app.name', 'Aperture');
        }
        View::share('siteTitle', $siteTitle);

        $this->app->singleton(function (Application $application): NtopNgService {
            $dbConfig = $this->getIntegrationDbConfig('ntopng');

            return new NtopNgService(
                endpoint: (string) ($dbConfig['endpoint'] ?? ''),
                username: (string) ($dbConfig['username'] ?? ''),
                password: (string) ($dbConfig['password'] ?? ''),
                interface: (int) ($dbConfig['interface'] ?? 0),
            );
        });

        $this->app->singleton(function (Application $application): BorealisService {
            $dbConfig = $this->getIntegrationDbConfig('borealis');

            return new BorealisService(
                clientId: (string) ($dbConfig['client_id'] ?? ''),
                clientSecret: (string) ($dbConfig['client_secret'] ?? ''),
                endpoint: (string) ($dbConfig['endpoint'] ?? ''),
            );
        });

        $this->app->singleton(function (Application $application): NetworkInventoryInterface {
            $dbConfig = $this->getIntegrationDbConfig('librenms');
            $inner = new LibreNmsService(
                endpoint: (string) ($dbConfig['endpoint'] ?? ''),
                apiToken: (string) ($dbConfig['api_key'] ?? ''),
            );

            return new CachedNetworkInventoryService(
                $inner,
                $application->make('cache.store'),
            );
        });

        $this->app->singleton(function (Application $application): DhcpInterface {
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

        $this->app->singleton(function (Application $application): DnsFilteringInterface {
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

        $this->app->singleton(function (Application $application): NetworkSwitchInterface {
            return $application->make(SwitchServiceFactory::class)->make($this->getDefaultSwitchConfig());
        });

        $this->app->singleton(function (Application $app): MacAddressResolverInterface {
            return new MacAddressResolver(
                $app->make(DhcpInterface::class),
                $app->make(NetworkInventoryInterface::class),
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

    protected function getDefaultSwitchConfig(): SwitchConfig
    {
        try {
            $switchConfig = SwitchConfig::query()->where('enabled', true)->orderBy('id')->first();
            if ($switchConfig instanceof SwitchConfig) {
                return $switchConfig;
            }
        } catch (Throwable) {
            // DB not available — use config fallback
        }

        return new SwitchConfig([
            'name' => 'Default Cisco Switch',
            'hostname' => (string) config('aperture.cisco.hostname', ''),
            'type' => 'cisco',
            'username' => (string) config('aperture.cisco.username', ''),
            'password' => (string) config('aperture.cisco.password', ''),
            'enable_password' => (string) config('aperture.cisco.enablePassword', ''),
            'enabled' => true,
            'port' => 22,
            'timeout' => (int) config('aperture.cisco.timeout', 5),
        ]);
    }
}
