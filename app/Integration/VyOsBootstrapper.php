<?php

declare(strict_types=1);

namespace App\Integration;

use App\Enums\Capability;
use App\Enums\Integration;
use App\Services\VyOs\VyOsClient;
use App\Services\VyOs\VyOsDhcpService;
use App\Services\VyOs\VyOsIpMacResolver;
use Illuminate\Contracts\Foundation\Application;

final class VyOsBootstrapper implements IntegrationBootstrapper
{
    public function integration(): Integration
    {
        return Integration::VyOs;
    }

    public function providers(): array
    {
        return [
            Capability::Dhcp->value => fn (Application $app): VyOsDhcpService => new VyOsDhcpService(
                $app->make(VyOsClient::class),
                (int) (InstallGuard::config(Integration::VyOs->value)['pool_size'] ?? 0),
            ),
            Capability::IpMac->value => fn (Application $app): VyOsIpMacResolver => new VyOsIpMacResolver(
                $app->make(VyOsClient::class),
            ),
        ];
    }
}
