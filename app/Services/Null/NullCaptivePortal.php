<?php

declare(strict_types=1);

namespace App\Services\Null;

use App\Services\Interfaces\CaptivePortalInterface;
use App\Services\ValueObjects\ReconcileResult;

class NullCaptivePortal implements CaptivePortalInterface
{
    public function addIp(string $ip, string $description): void {}

    public function removeIp(string $ip): void {}

    public function addAllowedHostnames(array $hostnames): void {}

    public function reconcile(bool $dryRun = false): ReconcileResult
    {
        return new ReconcileResult(added: [], removed: [], unchanged: [], errors: []);
    }
}
