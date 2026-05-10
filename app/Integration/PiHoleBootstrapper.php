<?php

declare(strict_types=1);

namespace App\Integration;

use App\Enums\Capability;
use App\Enums\Integration;
use App\Models\CapabilityAssignment;
use App\Models\IntegrationConfig;
use App\Services\Interfaces\DnsFilteringInterface;
use App\Services\Null\NullDnsFiltering;
use App\Services\PiHole\PiHoleService;
use GuzzleHttp\Client;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

final class PiHoleBootstrapper implements IntegrationBootstrapper
{
    public function register(Application $app): void
    {
        // dns-filtering
        $app->bind(function (): DnsFilteringInterface {
            if ($this->isActive(Capability::DnsFiltering->value)) {
                $dbConfig = $this->getIntegrationDbConfig();
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
    }

    private function isActive(string $capability): bool
    {
        try {
            return CapabilityAssignment::isActiveProvider(Integration::PiHole->value, $capability);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getIntegrationDbConfig(): array
    {
        try {
            return IntegrationConfig::getAll(Integration::PiHole->value);
        } catch (Throwable) {
            return [];
        }
    }
}
