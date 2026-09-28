<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use App\Models\SwitchConfig;
use Throwable;

class SwitchConnectionTester
{
    public function __construct(private readonly SwitchServiceFactory $factory) {}

    public function test(SwitchConfig $switchConfig): SwitchConnectionResult
    {
        $start = microtime(true);

        try {
            $ports = $this->factory->make($switchConfig)->getAllPorts();
        } catch (Throwable $throwable) {
            return new SwitchConnectionResult(false, 0, round(microtime(true) - $start, 3), $throwable);
        }

        return new SwitchConnectionResult(true, $ports->count(), round(microtime(true) - $start, 3));
    }
}
