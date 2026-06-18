<?php

declare(strict_types=1);

namespace App\Integration;

use App\Enums\Capability;
use App\Enums\Integration;
use App\Models\CapabilityAssignment;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Interfaces\PortMacInterface;
use App\Services\LibreNms\LibreNmsIpMacResolver;
use App\Services\LibreNms\LibreNmsPortMac;
use App\Services\LibreNms\LibreNmsService;
use App\Services\Null\NullIpMacResolver;
use App\Services\Null\NullPortMac;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

final class LibreNmsBootstrapper implements IntegrationBootstrapper
{
    public function register(Application $app): void
    {
        // ip-mac
        $app->bind(function (Application $app): IpMacResolverInterface {
            if ($this->isActive(Capability::IpMac->value)) {
                return new LibreNmsIpMacResolver(
                    $app->make(LibreNmsService::class),
                );
            }

            return new NullIpMacResolver;
        });

        // port-mac
        $app->bind(function (Application $app): PortMacInterface {
            if ($this->isActive(Capability::PortMac->value)) {
                return new LibreNmsPortMac(
                    $app->make(LibreNmsService::class),
                );
            }

            return new NullPortMac;
        });
    }

    private function isActive(string $capability): bool
    {
        try {
            return CapabilityAssignment::isActiveProvider(Integration::LibreNms->value, $capability);
        } catch (Throwable) {
            return false;
        }
    }
}
