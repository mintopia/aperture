<?php

declare(strict_types=1);

namespace App\Integration;

use App\Enums\Capability;
use App\Enums\Integration;
use App\Models\CapabilityAssignment;
use App\Models\IntegrationConfig;
use App\Models\SwitchConfig;
use App\Services\Cisco\CiscoDhcpService;
use App\Services\Interfaces\DhcpInterface;
use App\Services\NetworkSwitch\IosOutputParser;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

final class CiscoBootstrapper implements IntegrationBootstrapper
{
    public function register(Application $app): void
    {
        $app->extend(DhcpInterface::class, function (DhcpInterface $service, Application $app): DhcpInterface {
            if ($this->isActive(Capability::Dhcp->value)) {
                return $this->buildDhcpService($app) ?? $service;
            }

            return $service;
        });
    }

    private function buildDhcpService(Application $app): ?DhcpInterface
    {
        $switchId = IntegrationConfig::getValue(Integration::Cisco->value, 'switch_id');
        if ($switchId === null) {
            return null;
        }

        $switchConfig = SwitchConfig::find((int) $switchId);
        if ($switchConfig === null) {
            return null;
        }

        $factory = $app->make(SwitchServiceFactory::class);
        $transport = $factory->createTransport($switchConfig);

        return new CiscoDhcpService(
            transport: $transport,
            parser: new IosOutputParser,
            poolSize: (string) IntegrationConfig::getValue(Integration::Cisco->value, 'pool_size', '0'),
            ipv6Enabled: (bool) IntegrationConfig::getValue(Integration::Cisco->value, 'ipv6_enabled', true),
        );
    }

    private function isActive(string $capability): bool
    {
        try {
            return CapabilityAssignment::isActiveProvider(Integration::Cisco->value, $capability);
        } catch (Throwable) {
            return false;
        }
    }
}
