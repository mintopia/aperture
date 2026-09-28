<?php

declare(strict_types=1);

namespace App\Integration;

use App\Enums\Capability;
use App\Enums\Integration;
use App\Models\CapabilityAssignment;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Kea\KeaDhcpService;
use App\Services\Kea\KeaIpMacResolver;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

final class KeaBootstrapper implements IntegrationBootstrapper
{
    public function register(Application $app): void
    {
        $app->extend(DhcpInterface::class, function (DhcpInterface $service, Application $app): DhcpInterface {
            if ($this->isActive(Capability::Dhcp->value)) {
                return new KeaDhcpService;
            }

            return $service;
        });

        $app->extend(IpMacResolverInterface::class, function (IpMacResolverInterface $service, Application $app): IpMacResolverInterface {
            if ($this->isActive(Capability::IpMac->value)) {
                return new KeaIpMacResolver;
            }

            return $service;
        });
    }

    private function isActive(string $capability): bool
    {
        try {
            return CapabilityAssignment::isActiveProvider(Integration::Kea->value, $capability);
        } catch (Throwable) {
            return false;
        }
    }
}
