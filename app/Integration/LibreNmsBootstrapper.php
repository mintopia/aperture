<?php

declare(strict_types=1);

namespace App\Integration;

use App\Enums\Capability;
use App\Enums\Integration;
use App\Services\LibreNms\LibreNmsIpMacResolver;
use App\Services\LibreNms\LibreNmsPortMac;
use App\Services\LibreNms\LibreNmsService;
use Illuminate\Contracts\Foundation\Application;

final class LibreNmsBootstrapper implements IntegrationBootstrapper
{
    public function integration(): Integration
    {
        return Integration::LibreNms;
    }

    public function providers(): array
    {
        return [
            Capability::IpMac->value => fn (Application $app): LibreNmsIpMacResolver => new LibreNmsIpMacResolver(
                $app->make(LibreNmsService::class),
            ),
            Capability::PortMac->value => fn (Application $app): LibreNmsPortMac => new LibreNmsPortMac(
                $app->make(LibreNmsService::class),
            ),
        ];
    }
}
