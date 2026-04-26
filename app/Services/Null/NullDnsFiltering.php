<?php

declare(strict_types=1);

namespace App\Services\Null;

use App\Services\Interfaces\DnsFilteringInterface;
use App\Services\ValueObjects\ReconcileResult;

class NullDnsFiltering implements DnsFilteringInterface
{
    public function isEnabledForIp(string $ipAddress): bool
    {
        return false;
    }

    public function enableForIp(string $ipAddress): void {}

    public function disableForIp(string $ipAddress): void {}

    public function reconcile(bool $dryRun = false): ReconcileResult
    {
        return new ReconcileResult(added: [], removed: [], unchanged: [], errors: []);
    }
}
