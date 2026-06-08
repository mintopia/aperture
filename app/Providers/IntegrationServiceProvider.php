<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Integration;
use App\Integration\CiscoBootstrapper;
use App\Integration\LibreNmsBootstrapper;
use App\Integration\OpnSenseBootstrapper;
use App\Integration\PiHoleBootstrapper;
use App\Integration\PrometheusBootstrapper;
use App\Integration\VyOsBootstrapper;
use App\Models\IntegrationConfig;
use App\Services\BorealisService;
use App\Services\Firewalls\OpnSenseApiService;
use App\Services\Integration\BorealisTester;
use App\Services\Integration\IntegrationTesterRegistry;
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
use GuzzleHttp\Client;
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
        $this->app->singleton(function (): IntegrationTesterRegistry {
            $registry = new IntegrationTesterRegistry;
            $registry->register(Integration::OpnSense->value, new OpnSenseTester);
            $registry->register(Integration::PiHole->value, new PiHoleTester);
            $registry->register(Integration::LibreNms->value, new LibreNmsTester);
            $registry->register(Integration::Borealis->value, new BorealisTester);
            $registry->register(Integration::Prometheus->value, new PrometheusTester);
            $registry->register(Integration::Seatpicker->value, new SeatpickerTester);
            $registry->register(Integration::VyOs->value, new VyOsTester);

            return $registry;
        });
    }

    /**
     * Register shared singletons used by multiple capability bindings.
     */
    protected function registerSharedSingletons(): void
    {
        $this->app->singleton(function (): OpnSenseClient {
            $dbConfig = $this->getIntegrationDbConfig(Integration::OpnSense->value);

            $client = new Client([
                'verify' => (bool) ($dbConfig['verify_ssl'] ?? true),
                'base_uri' => $dbConfig['endpoint'] ?? '',
                'auth' => [$dbConfig['key'] ?? '', $dbConfig['secret'] ?? ''],
            ]);

            return new OpnSenseClient($client);
        });

        $this->app->singleton(function (): PrometheusService {
            $config = $this->getIntegrationDbConfig(Integration::Prometheus->value);

            return new PrometheusService(
                endpoint: (string) ($config['endpoint'] ?? ''),
                bearerToken: (string) ($config['bearer_token'] ?? ''),
                verifySsl: (bool) ($config['verify_ssl'] ?? true),
                defaultStep: (int) ($config['default_step'] ?? 60),
            );
        });

        $this->app->singleton(function (): LibreNmsService {
            $dbConfig = $this->getIntegrationDbConfig(Integration::LibreNms->value);

            return new LibreNmsService(
                endpoint: (string) ($dbConfig['endpoint'] ?? ''),
                apiToken: (string) ($dbConfig['api_key'] ?? ''),
            );
        });

        $this->app->singleton(function (): VyOsClient {
            $dbConfig = $this->getIntegrationDbConfig(Integration::VyOs->value);

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
    }

    /**
     * Register non-capability bindings (BorealisService, etc.).
     */
    protected function registerNonCapabilityBindings(): void
    {
        $this->app->singleton(function (): OpnSenseApiService {
            return new OpnSenseApiService($this->getIntegrationDbConfig(Integration::OpnSense->value));
        });

        $this->app->singleton(function (): BorealisService {
            $dbConfig = $this->getIntegrationDbConfig(Integration::Borealis->value);

            return new BorealisService(
                clientId: (string) ($dbConfig['client_id'] ?? ''),
                clientSecret: (string) ($dbConfig['client_secret'] ?? ''),
                endpoint: (string) ($dbConfig['endpoint'] ?? ''),
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
