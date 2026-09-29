<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use App\Integration\InstallGuard;
use App\Models\SwitchConfig;

class DefaultSwitchConfigResolver
{
    public function resolve(): SwitchConfig
    {
        $switchConfig = InstallGuard::tolerateMissingTable(
            fn (): ?SwitchConfig => SwitchConfig::query()->where('enabled', true)->orderBy('id')->first(),
            null,
        );

        return $switchConfig instanceof SwitchConfig ? $switchConfig : SwitchConfig::defaultFallback();
    }
}
