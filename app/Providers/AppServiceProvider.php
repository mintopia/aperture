<?php

namespace App\Providers;

use App\Models\IntegrationConfig;
use App\Models\SwitchConfig;
use App\Services\Auth\BorealisDeviceFlowService;
use App\Services\BorealisService;
use App\Services\CachedNetworkInventoryService;
use App\Services\CiscoService;
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
                endpoint: (string) ($dbConfig['endpoint'] ?? config('aperture.opnsense.endpoint', '')),
                key: (string) ($dbConfig['key'] ?? config('aperture.opnsense.key', '')),
                secret: (string) ($dbConfig['secret'] ?? config('aperture.opnsense.secret', '')),
                zoneId: (int) ($dbConfig['zone_id'] ?? config('aperture.opnsense.zoneid', 0)),
                verify: (bool) ($dbConfig['verify_ssl'] ?? config('aperture.opnsense.verify', true)),
                uploadRuleUuid: (string) ($dbConfig['ratelimit_up_uuid'] ?? config('aperture.opnsense.ratelimitUpUuid', '')),
                downloadRuleUuid: (string) ($dbConfig['ratelimit_down_uuid'] ?? config('aperture.opnsense.ratelimitDownUuid', '')),
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
                endpoint: (string) ($dbConfig['endpoint'] ?? config('aperture.ntopng.endpoint', '')),
                username: (string) ($dbConfig['username'] ?? config('aperture.ntopng.username', '')),
                password: (string) ($dbConfig['password'] ?? config('aperture.ntopng.password', '')),
                interface: (int) ($dbConfig['interface'] ?? config('aperture.ntopng.interface', 0)),
            );
        });

        $this->app->singleton(function (Application $application): BorealisService {
            return new BorealisService(
                clientId: config('aperture.borealis.client_id'),
                clientSecret: config('aperture.borealis.client_secret'),
                endpoint: config('aperture.borealis.endpoint'),
            );
        });

        $this->app->singleton(function (Application $application): NetworkInventoryInterface {
            $dbConfig = $this->getIntegrationDbConfig('librenms');
            $inner = new LibreNmsService(
                endpoint: (string) ($dbConfig['endpoint'] ?? config('aperture.librenms.endpoint', '')),
                apiToken: (string) ($dbConfig['api_key'] ?? config('aperture.librenms.api_token', '')),
            );

            return new CachedNetworkInventoryService(
                $inner,
                $application->make('cache.store'),
            );
        });

        $this->app->singleton(function (Application $application): DhcpInterface {
            $dbConfig = $this->getIntegrationDbConfig('dhcp');
            $opnsenseConfig = $this->getIntegrationDbConfig('opnsense');
            $client = new Client([
                'verify' => (bool) ($dbConfig['verify_ssl'] ?? $opnsenseConfig['verify_ssl'] ?? config('aperture.dhcp.verify', true)),
                'base_uri' => $dbConfig['endpoint'] ?? $opnsenseConfig['endpoint'] ?? config('aperture.dhcp.endpoint', ''),
                'auth' => [
                    $dbConfig['key'] ?? $opnsenseConfig['key'] ?? config('aperture.dhcp.key', ''),
                    $dbConfig['secret'] ?? $opnsenseConfig['secret'] ?? config('aperture.dhcp.secret', ''),
                ],
            ]);

            return new OpnSenseDhcpService(
                $client,
                (int) ($dbConfig['pool_size'] ?? config('aperture.dhcp.pool_size', 254)),
            );
        });

        $this->app->singleton(function (Application $application): DnsBlockingInterface {
            $dbConfig = $this->getIntegrationDbConfig('pihole');
            $client = new Client([
                'verify' => (bool) ($dbConfig['verify_ssl'] ?? config('aperture.pihole.verify')),
                'base_uri' => $dbConfig['endpoint'] ?? config('aperture.pihole.endpoint'),
            ]);

            return new PiHoleService(
                $client,
                (string) ($dbConfig['password'] ?? config('aperture.pihole.password')),
                (int) ($dbConfig['noblock_group_id'] ?? config('aperture.pihole.noblock_group_id', 1)),
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

            $ciscoService = new CiscoService($hostname);

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
