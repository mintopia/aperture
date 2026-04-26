<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\ValueObjects\ReconcileResult;

interface RateLimitingInterface
{
    public function limitIp(string $ip): void;

    public function unlimitIp(string $ip): void;

    public function reconcile(bool $dryRun = false): ReconcileResult;
}
