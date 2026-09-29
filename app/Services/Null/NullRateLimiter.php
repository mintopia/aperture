<?php

declare(strict_types=1);

namespace App\Services\Null;

use App\Services\Interfaces\RateLimitingInterface;
use App\Services\ValueObjects\ReconcileResult;

class NullRateLimiter implements RateLimitingInterface
{
    public function limitIp(string $ip): void {}

    public function unlimitIp(string $ip): void {}

    public function reconcile(bool $dryRun = false): ReconcileResult
    {
        return new ReconcileResult(added: [], removed: [], unchanged: [], errors: []);
    }
}
