<?php

namespace App\Providers;

use App\Models\IntegrationConfig;
use App\Models\SwitchConfig;
use App\Services\Auth\BorealisDeviceFlowService;
use App\Services\BorealisService;
use App\Services\CachedNetworkInventoryService;
use App\Services\CiscoService;
use App\Services\Dhcp\NullDhcpService;
use App\Services\Dhcp\OpnSenseDhcpService;
use App\Services\Firewalls\OpnSense;
use App\Services\Interfaces\AuthProviderInterface;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\DnsBlockingInterface;
use App\Services\Interfaces\FirewallBackendInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\Interfaces\NetworkInventoryInterface;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\LibreNmsService;
use App\Services\MacAddressResolver;
use App\Services\NetworkSwitch\CiscoSwitchAdapter;
use App\Services\NetworkSwitch\IosOutputParser;
use App\Services\NtopNgService;
use App\Services\PiHole\PiHoleService;
use App\Services\SshProxy\SshProxyClient;
use App\Services\SshProxy\SshProxyClientInterface;
use GuzzleHttp\Client;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AuthProviderInterface::class, BorealisDeviceFlowService::class);

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
            return new SshProxyClient(
                sprintf('http://%s:%d', config('aperture.ssh_proxy.host'), config('aperture.ssh_proxy.port')),
                (string) config('aperture.ssh_proxy.api_key'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
                    'leases' => '/api/kea/leases4/search',
                    'ipv4_ranges' => '/api/kea/dhcpv4/search',
                    'ipv6_ranges' => '/api/kea/dhcpv6/search',
                ],
                'dnsmasq' => [
                    'leases' => '/api/dnsmasq/leases/searchLease',
                    'ipv4_ranges' => '/api/dnsmasq/settings/searchDomain',
                    'ipv6_ranges' => '',
                ],
                default => [
                    'leases' => '/api/dhcpv4/leases/searchLease',
                    'ipv4_ranges' => '/api/dhcpv4/service/searchSubnet',
                    'ipv6_ranges' => '/api/dhcpv6/service/searchSubnet',
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
            );
        });

        $this->app->singleton(function (Application $application): DnsBlockingInterface {
            $dbConfig = $this->getIntegrationDbConfig('pihole');
            $client = new Client([
                'verify' => (bool) ($dbConfig['verify_ssl'] ?? true),
                'base_uri' => $dbConfig['endpoint'] ?? '',
            ]);

            return new PiHoleService(
                $client,
                (string) ($dbConfig['password'] ?? ''),
                (int) ($dbConfig['noblock_group_id'] ?? 1),
            );
        });

        $this->app->singleton(function (Application $application): NetworkSwitchInterface {
            $hostname = (string) config('aperture.cisco.hostname');

            try {
                $switchConfig = SwitchConfig::where('enabled', true)->first();
                if ($switchConfig) {
                    $hostname = $switchConfig->hostname;
                }
            } catch (Throwable) {
                // DB not available — use env config
            }

            $ciscoService = new CiscoService(
                hostname: $hostname,
                username: (string) config('aperture.cisco.username', ''),
                password: (string) config('aperture.cisco.password', ''),
                enablePassword: (string) config('aperture.cisco.enablePassword', ''),
                timeout: (int) config('aperture.cisco.timeout', 5),
            );

            return new CiscoSwitchAdapter($ciscoService, new IosOutputParser);
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
}
