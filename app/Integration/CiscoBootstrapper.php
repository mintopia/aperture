<?php

declare(strict_types=1);

namespace App\Integration;

use App\Enums\Capability;
use App\Enums\Integration;
use App\Models\SwitchConfig;
use App\Services\Cisco\CiscoDhcpService;
use App\Services\NetworkSwitch\IosOutputParser;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use Illuminate\Contracts\Foundation\Application;

final class CiscoBootstrapper implements IntegrationBootstrapper
{
    public function integration(): Integration
    {
        return Integration::Cisco;
    }

    public function providers(): array
    {
        return [
            Capability::Dhcp->value => fn (Application $app): ?CiscoDhcpService => $this->buildDhcpService($app),
        ];
    }

    private function buildDhcpService(Application $app): ?CiscoDhcpService
    {
        $config = InstallGuard::config(Integration::Cisco->value);

        $switchId = $config['switch_id'] ?? null;
        if ($switchId === null) {
            return null;
        }

        $switchConfig = SwitchConfig::find((int) $switchId);
        if ($switchConfig === null) {
            return null;
        }

        return new CiscoDhcpService(
            transport: $app->make(SwitchServiceFactory::class)->createTransport($switchConfig),
            parser: new IosOutputParser,
            poolSize: (string) ($config['pool_size'] ?? '0'),
            ipv6Enabled: (bool) ($config['ipv6_enabled'] ?? true),
            timezone: $switchConfig->timezone ?: 'UTC',
        );
    }
}
