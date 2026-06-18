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

    /**
     * Record a communication failure for a switch.
     *
     * When the failure count reaches the configured threshold, fires
     * SwitchUnreachable and marks the switch as circuit-broken.
     *
     * The circuit-open flag is stored with a cooldown TTL so the switch is
     * automatically retried (half-open) by the next scheduled sync once the
     * cooldown elapses. A failure at or beyond the threshold re-arms the
     * cooldown, so a failed half-open trial re-opens the circuit.
     */
    public function recordFailure(SwitchConfig $switch): void
    {
        $cacheKey = $this->cacheKey($switch);
        $count = (int) Cache::get($cacheKey, 0) + 1;

        Cache::put($cacheKey, $count);

        if ($count >= $this->failureThreshold) {
            Cache::put($this->openKey($switch), true, $this->cooldown);

            if ($count === $this->failureThreshold) {
                SwitchUnreachable::dispatch($switch, $count);
            }
        }
    }

    /**
     * Record a successful communication with a switch, resetting the failure counter.
     */
    public function recordSuccess(SwitchConfig $switch): void
    {
        Cache::forget($this->cacheKey($switch));
        Cache::forget($this->openKey($switch));
    }

    /**
     * Check whether a switch is available (circuit is closed).
     */
    public function isAvailable(SwitchConfig $switch): bool
    {
        return ! Cache::get($this->openKey($switch), false);
    }

    /**
     * Manually reset the circuit breaker for a switch, re-enabling communication.
     */
    public function reset(SwitchConfig $switch): void
    {
        Cache::forget($this->cacheKey($switch));
        Cache::forget($this->openKey($switch));
    }

    /**
     * Get the current failure count for a switch.
     */
    public function getFailureCount(SwitchConfig $switch): int
    {
        return (int) Cache::get($this->cacheKey($switch), 0);
    }

    /**
     * Build the cache key for failure count tracking.
     */
    private function cacheKey(SwitchConfig $switch): string
    {
        return sprintf('circuit_breaker:failures:%d', $switch->id);
    }

    /**
     * Build the cache key for the circuit-open flag.
     */
    private function openKey(SwitchConfig $switch): string
    {
        return sprintf('circuit_breaker:open:%d', $switch->id);
    }
}
