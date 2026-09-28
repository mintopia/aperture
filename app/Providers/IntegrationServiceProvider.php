<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Integration;
use App\Integration\CiscoBootstrapper;
use App\Integration\KeaBootstrapper;
use App\Integration\LibreNmsBootstrapper;
use App\Integration\OpnSenseBootstrapper;
use App\Integration\PiHoleBootstrapper;
use App\Integration\PrometheusBootstrapper;
use App\Integration\VyOsBootstrapper;
use App\Models\IntegrationConfig;
use App\Services\BorealisService;
use App\Services\LibreNms\LibreNmsService;
use App\Services\OpnSense\OpnSenseClient;
use App\Services\Prometheus\PrometheusService;
use App\Services\VyOs\VyOsClient;
use Illuminate\Support\ServiceProvider;

class IntegrationServiceProvider extends ServiceProvider
{
    /**
     * Register external service bindings.
     */
    public function register(): void
    {
        $this->registerSharedSingletons();
        $this->registerCapabilityBindings();
        $this->registerNonCapabilityBindings();
    }

    /**
     * Register shared singletons used by multiple capability bindings.
     */
    protected function registerSharedSingletons(): void
    {
        $this->app->singleton(function (): OpnSenseClient {
            return OpnSenseClient::fromConfig(IntegrationConfig::safeGetAll(Integration::OpnSense->value));
        });

        $this->app->singleton(function (): PrometheusService {
            $config = IntegrationConfig::safeGetAll(Integration::Prometheus->value);

            return new PrometheusService(
                endpoint: (string) ($config['endpoint'] ?? ''),
                bearerToken: (string) ($config['bearer_token'] ?? ''),
                verifySsl: (bool) ($config['verify_ssl'] ?? true),
                defaultStep: (int) ($config['default_step'] ?? 60),
            );
        });

        $this->app->singleton(function (): LibreNmsService {
            $dbConfig = IntegrationConfig::safeGetAll(Integration::LibreNms->value);

            return new LibreNmsService(
                endpoint: (string) ($dbConfig['endpoint'] ?? ''),
                apiToken: (string) ($dbConfig['api_key'] ?? ''),
            );
        });

        $this->app->singleton(function (): VyOsClient {
            $dbConfig = IntegrationConfig::safeGetAll(Integration::VyOs->value);

            return new VyOsClient(
                endpoint: (string) ($dbConfig['endpoint'] ?? ''),
                apiKey: (string) ($dbConfig['api_key'] ?? ''),
                verifySsl: (bool) ($dbConfig['verify_ssl'] ?? true),
            );
        });
    }

    /**
     * Register all capability-gated bindings via per-integration bootstrappers.
     */
    protected function registerCapabilityBindings(): void
    {
        (new OpnSenseBootstrapper)->register($this->app);
        (new PrometheusBootstrapper)->register($this->app);
        (new LibreNmsBootstrapper)->register($this->app);
        (new PiHoleBootstrapper)->register($this->app);
        (new VyOsBootstrapper)->register($this->app);
        (new CiscoBootstrapper)->register($this->app);
        (new KeaBootstrapper)->register($this->app);
    }

    /**
     * Register non-capability bindings (BorealisService, etc.).
     */
    protected function registerNonCapabilityBindings(): void
    {
        $this->app->singleton(function (): BorealisService {
            $dbConfig = IntegrationConfig::safeGetAll(Integration::Borealis->value);

            return new BorealisService(
                clientId: (string) ($dbConfig['client_id'] ?? ''),
                clientSecret: (string) ($dbConfig['client_secret'] ?? ''),
                endpoint: (string) ($dbConfig['endpoint'] ?? ''),
            );
        });
    }
}
