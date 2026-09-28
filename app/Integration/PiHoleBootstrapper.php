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
            Capability::DnsFiltering->value => function (): PiHoleService {
                $config = InstallGuard::config(Integration::PiHole->value);

                return new PiHoleService(
                    (string) ($config['endpoint'] ?? ''),
                    (string) ($config['password'] ?? ''),
                    (int) ($config['filtered_group_id'] ?? 1),
                    (bool) ($config['verify_ssl'] ?? true),
                );
            },
        ];
    }
}
