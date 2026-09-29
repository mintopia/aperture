<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Integration;
use App\Integration\CapabilityResolver;
use App\Integration\CiscoBootstrapper;
use App\Integration\InstallGuard;
use App\Integration\IntegrationBootstrapper;
use App\Integration\KeaBootstrapper;
use App\Integration\LibreNmsBootstrapper;
use App\Integration\OpnSenseBootstrapper;
use App\Integration\PiHoleBootstrapper;
use App\Integration\PrometheusBootstrapper;
use App\Integration\VyOsBootstrapper;
use App\Models\CapabilityAssignment;
use App\Models\IntegrationConfig;
use App\Services\BorealisService;
use App\Services\LibreNms\LibreNmsService;
use App\Services\OpnSense\OpnSenseClient;
use App\Services\Prometheus\PrometheusService;
use App\Services\VyOs\VyOsClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class IntegrationServiceProvider extends ServiceProvider
{
    /** @var list<class-string> */
    private const CONFIG_DERIVED = [
        OpnSenseClient::class,
        PrometheusService::class,
        LibreNmsService::class,
        VyOsClient::class,
        BorealisService::class,
    ];

    public function register(): void
    {
        $this->registerSharedClients();
        $this->registerCapabilityBindings();
        $this->registerNonCapabilityBindings();
    }

    protected function registerSharedClients(): void
    {
        $this->app->scoped(function (): OpnSenseClient {
            return OpnSenseClient::fromConfig(InstallGuard::config(Integration::OpnSense->value));
        });

        $this->app->scoped(function (): PrometheusService {
            $config = InstallGuard::config(Integration::Prometheus->value);

            return new PrometheusService(
                endpoint: (string) ($config['endpoint'] ?? ''),
                bearerToken: (string) ($config['bearer_token'] ?? ''),
                verifySsl: (bool) ($config['verify_ssl'] ?? true),
                defaultStep: (int) ($config['default_step'] ?? 60),
            );
        });

        $this->app->scoped(function (): LibreNmsService {
            $dbConfig = InstallGuard::config(Integration::LibreNms->value);

            return new LibreNmsService(
                endpoint: (string) ($dbConfig['endpoint'] ?? ''),
                apiToken: (string) ($dbConfig['api_key'] ?? ''),
                verifySsl: (bool) ($dbConfig['verify_ssl'] ?? true),
            );
        });

        $this->app->scoped(function (): VyOsClient {
            $dbConfig = InstallGuard::config(Integration::VyOs->value);

            return new VyOsClient(
                endpoint: (string) ($dbConfig['endpoint'] ?? ''),
                apiKey: (string) ($dbConfig['api_key'] ?? ''),
                verifySsl: (bool) ($dbConfig['verify_ssl'] ?? true),
            );
        });
    }

    protected function registerCapabilityBindings(): void
    {
        $this->app->scoped(fn (Application $app): CapabilityResolver => new CapabilityResolver($app, $this->bootstrappers()));

        foreach (CapabilityResolver::contracts() as $capability => $contract) {
            $this->app->bind($contract['interface'], fn (Application $app): object => $app->make(CapabilityResolver::class)->resolve($capability));
        }
    }

    /**
     * @return list<IntegrationBootstrapper>
     */
    protected function bootstrappers(): array
    {
        return [
            new OpnSenseBootstrapper,
            new PrometheusBootstrapper,
            new LibreNmsBootstrapper,
            new PiHoleBootstrapper,
            new VyOsBootstrapper,
            new CiscoBootstrapper,
            new KeaBootstrapper,
        ];
    }

    public function boot(): void
    {
        $forgetResolver = function (): void {
            $this->app->forgetInstance(CapabilityResolver::class);
        };
        CapabilityAssignment::saved($forgetResolver);
        CapabilityAssignment::deleted($forgetResolver);

        $forgetClients = function (): void {
            foreach (self::CONFIG_DERIVED as $abstract) {
                $this->app->forgetInstance($abstract);
            }
        };
        IntegrationConfig::saved($forgetClients);
        IntegrationConfig::deleted($forgetClients);
    }

    protected function registerNonCapabilityBindings(): void
    {
        $this->app->scoped(function (): BorealisService {
            $dbConfig = InstallGuard::config(Integration::Borealis->value);

            return new BorealisService(
                clientId: (string) ($dbConfig['client_id'] ?? ''),
                clientSecret: (string) ($dbConfig['client_secret'] ?? ''),
                endpoint: (string) ($dbConfig['endpoint'] ?? ''),
            );
        });
    }
}
