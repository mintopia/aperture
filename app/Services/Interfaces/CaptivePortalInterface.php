<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\ValueObjects\ReconcileResult;

interface CaptivePortalInterface
{
    public function addIp(string $ip, string $description): void;

    public function removeIp(string $ip): void;

    /** @param array<int, string> $hostnames */
    public function addAllowedHostnames(array $hostnames): void;

    public function reconcile(bool $dryRun = false): ReconcileResult;
}
