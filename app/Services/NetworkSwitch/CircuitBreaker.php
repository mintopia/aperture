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

    public function state(SwitchConfig $switch): CircuitState
    {
        $entry = Cache::get($this->stateKey($switch));

        if (! is_array($entry)) {
            return CircuitState::Closed;
        }

        $state = CircuitState::from($entry['state']);

        if ($state === CircuitState::Open && now()->getTimestamp() >= $entry['retry_at']) {
            $this->store($switch, CircuitState::HalfOpen);

            return CircuitState::HalfOpen;
        }

        return $state;
    }

    public function recordFailure(SwitchConfig $switch): void
    {
        $state = $this->state($switch);
        $count = (int) Cache::get($this->failuresKey($switch), 0) + 1;

        Cache::put($this->failuresKey($switch), $count);

        if ($state === CircuitState::HalfOpen) {
            $this->open($switch);

            return;
        }

        if ($state === CircuitState::Closed && $count >= $this->failureThreshold) {
            $this->open($switch);

            if ($count === $this->failureThreshold) {
                event(new SwitchUnreachable($switch, $count));
            }
        }
    }

    public function recordSuccess(SwitchConfig $switch): void
    {
        $this->reset($switch);
    }

    public function isAvailable(SwitchConfig $switch): bool
    {
        return $this->state($switch) !== CircuitState::Open;
    }

    public function reset(SwitchConfig $switch): void
    {
        Cache::forget($this->failuresKey($switch));
        Cache::forget($this->stateKey($switch));
    }

    private function open(SwitchConfig $switch): void
    {
        $this->store($switch, CircuitState::Open, now()->getTimestamp() + $this->cooldown);
    }

    private function store(SwitchConfig $switch, CircuitState $state, ?int $retryAt = null): void
    {
        Cache::forever($this->stateKey($switch), ['state' => $state->value, 'retry_at' => $retryAt]);
    }

    private function failuresKey(SwitchConfig $switch): string
    {
        return sprintf('circuit_breaker:failures:%d', $switch->id);
    }

    private function stateKey(SwitchConfig $switch): string
    {
        return sprintf('circuit_breaker:state:%d', $switch->id);
    }
}
