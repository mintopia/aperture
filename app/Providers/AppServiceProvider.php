<?php

namespace App\Providers;

use App\Services\Auth\BorealisDeviceFlowService;
use App\Services\BorealisService;
use App\Services\CiscoService;
use App\Services\Dhcp\OpnSenseDhcpService;
use App\Services\Firewalls\OpnSense;
use App\Services\Interfaces\AuthProviderInterface;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\DnsBlockingInterface;
use App\Services\Interfaces\FirewallBackendInterface;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\LibreNmsService;
use App\Services\NetworkSwitch\CiscoSwitchAdapter;
use App\Services\NetworkSwitch\IosOutputParser;
use App\Services\NtopNgService;
use App\Services\PiHole\PiHoleService;
use GuzzleHttp\Client;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AuthProviderInterface::class, BorealisDeviceFlowService::class);
        $this->app->bind(FirewallBackendInterface::class, OpnSense::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->singleton(function (Application $application): NtopNgService {
            return new NtopNgService(
                endpoint: config('aperture.ntopng.endpoint'),
                username: config('aperture.ntopng.username'),
                password: config('aperture.ntopng.password'),
                interface: config('aperture.ntopng.interface'),
            );
        });

        $this->app->singleton(function (Application $application): BorealisService {
            return new BorealisService(
                clientId: config('aperture.borealis.client_id'),
                clientSecret: config('aperture.borealis.client_secret'),
                endpoint: config('aperture.borealis.endpoint'),
            );
        });

        $this->app->singleton(function (Application $application): LibreNmsService {
            return new LibreNmsService(
                endpoint: config('aperture.librenms.endpoint', ''),
                apiToken: config('aperture.librenms.api_token', ''),
            );
        });

        $this->app->singleton(function (Application $application): DhcpInterface {
            $client = new Client([
                'verify' => config('aperture.dhcp.verify'),
                'base_uri' => config('aperture.dhcp.endpoint'),
                'auth' => [
                    config('aperture.dhcp.key'),
                    config('aperture.dhcp.secret'),
                ],
            ]);

            return new OpnSenseDhcpService($client);
        });

        $this->app->singleton(function (Application $application): DnsBlockingInterface {
            $client = new Client([
                'verify' => config('aperture.pihole.verify'),
                'base_uri' => config('aperture.pihole.endpoint'),
            ]);

            return new PiHoleService(
                $client,
                (string) config('aperture.pihole.password'),
                (int) config('aperture.pihole.noblock_group_id', 1),
            );
        });

        $this->app->singleton(function (Application $application): NetworkSwitchInterface {
            $ciscoService = new CiscoService(
                (string) config('aperture.cisco.hostname'),
            );

            return new CiscoSwitchAdapter($ciscoService, new IosOutputParser);
        });
    }
}
