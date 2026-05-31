<?php

declare(strict_types=1);

namespace App\Integration;

use App\Enums\Capability;
use App\Enums\Integration;
use App\Models\CapabilityAssignment;
use App\Models\IntegrationConfig;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Null\NullDhcpService;
use App\Services\Null\NullIpMacResolver;
use App\Services\VyOs\VyOsClient;
use App\Services\VyOs\VyOsDhcpService;
use App\Services\VyOs\VyOsIpMacResolver;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

final class VyOsBootstrapper implements IntegrationBootstrapper
{
    public function register(Application $app): void
    {
        // dhcp
        $app->bind(function (Application $app): DhcpInterface {
            if ($this->isActive(Capability::Dhcp->value)) {
                return new VyOsDhcpService(
                    $app->make(VyOsClient::class),
                    (int) IntegrationConfig::getValue(Integration::VyOs->value, 'pool_size', '0'),
                );
            }

            return new NullDhcpService;
        });

        // ip-mac
        $app->bind(function (Application $app): IpMacResolverInterface {
            if ($this->isActive(Capability::IpMac->value)) {
                return new VyOsIpMacResolver(
                    $app->make(VyOsClient::class),
                );
            }

            return new NullIpMacResolver;
        });
    }

    private function isActive(string $capability): bool
    {
        try {
            return CapabilityAssignment::isActiveProvider(Integration::VyOs->value, $capability);
        } catch (Throwable) {
            return false;
        }
    }
}
