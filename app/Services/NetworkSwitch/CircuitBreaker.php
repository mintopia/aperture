<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use App\Events\SwitchUnreachable;
use App\Models\SwitchConfig;
use Illuminate\Support\Facades\Cache;

class CircuitBreaker
{
    private int $failureThreshold;

    private int $cooldown;

    public function __construct()
    {
        $this->failureThreshold = (int) config('aperture.circuit_breaker.failure_threshold', 3);
        $this->cooldown = (int) config('aperture.circuit_breaker.cooldown', 300);
    }

    public function recordFailure(SwitchConfig $switch): void
    {
        $cacheKey = $this->cacheKey($switch);
        $count = (int) Cache::get($cacheKey, 0) + 1;

        Cache::put($cacheKey, $count);

        if ($count >= $this->failureThreshold) {
            Cache::put($this->openKey($switch), true, $this->cooldown);

            if ($count === $this->failureThreshold) {
                event(new SwitchUnreachable($switch, $count));
            }
        }
    }

    public function recordSuccess(SwitchConfig $switch): void
    {
        Cache::forget($this->cacheKey($switch));
        Cache::forget($this->openKey($switch));
    }

    public function isAvailable(SwitchConfig $switch): bool
    {
        return ! Cache::get($this->openKey($switch), false);
    }

    public function reset(SwitchConfig $switch): void
    {
        Cache::forget($this->cacheKey($switch));
        Cache::forget($this->openKey($switch));
    }

    private function cacheKey(SwitchConfig $switch): string
    {
        return sprintf('circuit_breaker:failures:%d', $switch->id);
    }

    private function openKey(SwitchConfig $switch): string
    {
        return sprintf('circuit_breaker:open:%d', $switch->id);
    }
}
