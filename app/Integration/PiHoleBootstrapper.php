<?php

declare(strict_types=1);

namespace App\Integration;

use App\Enums\Capability;
use App\Enums\Integration;
use App\Services\PiHole\PiHoleService;

final class PiHoleBootstrapper implements IntegrationBootstrapper
{
    public function integration(): Integration
    {
        return Integration::PiHole;
    }

    public function providers(): array
    {
        return [
            Capability::DnsFiltering->value => fn (): PiHoleService => PiHoleService::fromConfig(InstallGuard::config(Integration::PiHole->value)),
        ];
    }
}
