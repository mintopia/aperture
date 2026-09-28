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
use App\Services\Firewalls\OpnSenseApiService;
use App\Services\Integration\BorealisTester;
use App\Services\Integration\CiscoTester;
use App\Services\Integration\IntegrationTesterRegistry;
use App\Services\Integration\KeaTester;
use App\Services\Integration\LibreNmsTester;
use App\Services\Integration\OpnSenseTester;
use App\Services\Integration\PiHoleTester;
use App\Services\Integration\PrometheusTester;
use App\Services\Integration\SeatpickerTester;
use App\Services\Integration\VyOsTester;
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
        OpnSenseApiService::class,
        PrometheusService::class,
        LibreNmsService::class,
        VyOsClient::class,
        BorealisService::class,
    ];

    /**
     * Register external service bindings.
     */
    public function register(): void
    {
        $this->registerIntegrationTesters();
        $this->registerSharedClients();
        $this->registerCapabilityBindings();
        $this->registerNonCapabilityBindings();
    }

    /**
     * Register the integration tester registry.
     */
    protected function registerIntegrationTesters(): void
    {
        $this->app->singleton(function (): IntegrationTesterRegistry {
            $registry = new IntegrationTesterRegistry;
            $registry->register(Integration::OpnSense->value, new OpnSenseTester);
            $registry->register(Integration::PiHole->value, new PiHoleTester);
            $registry->register(Integration::LibreNms->value, new LibreNmsTester);
            $registry->register(Integration::Borealis->value, new BorealisTester);
            $registry->register(Integration::Prometheus->value, new PrometheusTester);
            $registry->register(Integration::Seatpicker->value, new SeatpickerTester);
            $registry->register(Integration::VyOs->value, new VyOsTester);
            $registry->register(Integration::Cisco->value, $this->app->make(CiscoTester::class));
            $registry->register(Integration::Kea->value, new KeaTester);

            return $registry;
        });
    }

    protected function registerSharedClients(): void
    {
        $this->app->scoped(function (): OpnSenseClient {
            $dbConfig = InstallGuard::config(Integration::OpnSense->value);

            return new OpnSenseClient(
                endpoint: (string) ($dbConfig['endpoint'] ?? ''),
                key: (string) ($dbConfig['key'] ?? ''),
                secret: (string) ($dbConfig['secret'] ?? ''),
                verifySsl: (bool) ($dbConfig['verify_ssl'] ?? true),
            );
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

    /**
     * Register non-capability bindings (BorealisService, etc.).
     */
    protected function registerNonCapabilityBindings(): void
    {
        $this->app->scoped(function (): OpnSenseApiService {
            return new OpnSenseApiService(InstallGuard::config(Integration::OpnSense->value));
        });

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
