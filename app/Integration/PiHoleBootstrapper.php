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
use Illuminate\Contracts\Foundation\Application;
use Throwable;

final class PiHoleBootstrapper implements IntegrationBootstrapper
{
    public function register(Application $app): void
    {
        // dns-filtering
        $app->bind(function (): DnsFilteringInterface {
            if ($this->isActive(Capability::DnsFiltering->value)) {
                return PiHoleService::fromConfig(IntegrationConfig::safeGetAll(Integration::PiHole->value));
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
}
