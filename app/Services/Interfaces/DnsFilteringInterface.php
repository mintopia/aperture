<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\ValueObjects\ReconcileResult;

interface DnsFilteringInterface
{
    public function isEnabledForIp(string $ipAddress): bool;

    public function enableForIp(string $ipAddress): void;

    public function disableForIp(string $ipAddress): void;

    public function reconcile(bool $dryRun = false): ReconcileResult;
}
