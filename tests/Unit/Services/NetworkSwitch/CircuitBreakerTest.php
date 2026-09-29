<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch;

use App\Events\SwitchUnreachable;
use App\Models\SwitchConfig;
use App\Services\NetworkSwitch\CircuitBreaker;
use App\Services\NetworkSwitch\CircuitState;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CircuitBreakerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private CircuitBreaker $circuitBreaker;

    protected function setUp(): void
    {
        parent::setUp();

        config(['aperture.circuit_breaker.failure_threshold' => 3]);
        Cache::flush();
        $this->circuitBreaker = new CircuitBreaker;
    }

    public function test_new_switch_is_available(): void
    {
        $switch = SwitchConfig::factory()->create();

        $this->assertTrue($this->circuitBreaker->isAvailable($switch));
    }

    public function test_switch_remains_available_below_threshold(): void
    {
        $switch = SwitchConfig::factory()->create();

        $this->circuitBreaker->recordFailure($switch);
        $this->circuitBreaker->recordFailure($switch);

        $this->assertTrue($this->circuitBreaker->isAvailable($switch));
    }

    public function test_switch_becomes_unavailable_at_threshold(): void
    {
        Event::fake([SwitchUnreachable::class]);

        $switch = SwitchConfig::factory()->create();

        $this->circuitBreaker->recordFailure($switch);
        $this->circuitBreaker->recordFailure($switch);
        $this->circuitBreaker->recordFailure($switch);

        $this->assertFalse($this->circuitBreaker->isAvailable($switch));
    }

    public function test_switch_unreachable_event_fired_when_circuit_opens(): void
    {
        Event::fake([SwitchUnreachable::class]);

        $switch = SwitchConfig::factory()->create();

        $this->circuitBreaker->recordFailure($switch);
        $this->circuitBreaker->recordFailure($switch);
        $this->circuitBreaker->recordFailure($switch);

        Event::assertDispatched(SwitchUnreachable::class, function (SwitchUnreachable $event) use ($switch): bool {
            return $event->switchConfig->is($switch) && $event->failureCount === 3;
        });
    }

    public function test_event_fires_only_once_on_repeated_failures(): void
    {
        Event::fake([SwitchUnreachable::class]);

        $switch = SwitchConfig::factory()->create();

        $this->circuitBreaker->recordFailure($switch);
        $this->circuitBreaker->recordFailure($switch);
        $this->circuitBreaker->recordFailure($switch);
        $this->circuitBreaker->recordFailure($switch);
        $this->circuitBreaker->recordFailure($switch);

        Event::assertDispatchedTimes(SwitchUnreachable::class, 1);
    }

    public function test_record_success_resets_failure_counter(): void
    {
        Event::fake([SwitchUnreachable::class]);

        $switch = SwitchConfig::factory()->create();

        $this->circuitBreaker->recordFailure($switch);
        $this->circuitBreaker->recordFailure($switch);
        $this->circuitBreaker->recordSuccess($switch);

        $this->assertTrue($this->circuitBreaker->isAvailable($switch));
    }

    public function test_manual_reset_re_enables_switch(): void
    {
        Event::fake([SwitchUnreachable::class]);

        $switch = SwitchConfig::factory()->create();

        $this->circuitBreaker->recordFailure($switch);
        $this->circuitBreaker->recordFailure($switch);
        $this->circuitBreaker->recordFailure($switch);

        $this->assertFalse($this->circuitBreaker->isAvailable($switch));

        $this->circuitBreaker->reset($switch);

        $this->assertTrue($this->circuitBreaker->isAvailable($switch));
    }

    public function test_failure_tracking_is_per_switch(): void
    {
        Event::fake([SwitchUnreachable::class]);

        $switchA = SwitchConfig::factory()->create();
        $switchB = SwitchConfig::factory()->create();

        $this->circuitBreaker->recordFailure($switchA);
        $this->circuitBreaker->recordFailure($switchA);
        $this->circuitBreaker->recordFailure($switchA);

        $this->assertFalse($this->circuitBreaker->isAvailable($switchA));
        $this->assertTrue($this->circuitBreaker->isAvailable($switchB));
    }

    public function test_configurable_threshold(): void
    {
        Event::fake([SwitchUnreachable::class]);

        config(['aperture.circuit_breaker.failure_threshold' => 5]);
        $circuitBreaker = new CircuitBreaker;

        $switch = SwitchConfig::factory()->create();

        for ($i = 0; $i < 4; $i++) {
            $circuitBreaker->recordFailure($switch);
        }

        $this->assertTrue($circuitBreaker->isAvailable($switch));

        $circuitBreaker->recordFailure($switch);

        $this->assertFalse($circuitBreaker->isAvailable($switch));
    }

    public function test_default_threshold_is_three(): void
    {
        config(['aperture.circuit_breaker' => null]);
        $circuitBreaker = new CircuitBreaker;

        $switch = SwitchConfig::factory()->create();

        $this->circuitBreaker->recordFailure($switch);
        $this->circuitBreaker->recordFailure($switch);

        $this->assertTrue($circuitBreaker->isAvailable($switch));
    }

    public function test_success_after_circuit_opens_resets_and_re_enables(): void
    {
        Event::fake([SwitchUnreachable::class]);

        $switch = SwitchConfig::factory()->create();

        $this->circuitBreaker->recordFailure($switch);
        $this->circuitBreaker->recordFailure($switch);
        $this->circuitBreaker->recordFailure($switch);

        $this->assertFalse($this->circuitBreaker->isAvailable($switch));

        $this->circuitBreaker->recordSuccess($switch);

        $this->assertTrue($this->circuitBreaker->isAvailable($switch));
    }

    public function test_circuit_recovers_automatically_after_cooldown(): void
    {
        Event::fake([SwitchUnreachable::class]);

        config(['aperture.circuit_breaker.cooldown' => 300]);
        $circuitBreaker = new CircuitBreaker;

        $switch = SwitchConfig::factory()->create();

        $circuitBreaker->recordFailure($switch);
        $circuitBreaker->recordFailure($switch);
        $circuitBreaker->recordFailure($switch);

        $this->assertFalse($circuitBreaker->isAvailable($switch));

        $this->travel(301)->seconds();

        $this->assertTrue($circuitBreaker->isAvailable($switch));
    }

    public function test_circuit_reopens_when_trial_fails_after_cooldown(): void
    {
        Event::fake([SwitchUnreachable::class]);

        config(['aperture.circuit_breaker.cooldown' => 300]);
        $circuitBreaker = new CircuitBreaker;

        $switch = SwitchConfig::factory()->create();

        $circuitBreaker->recordFailure($switch);
        $circuitBreaker->recordFailure($switch);
        $circuitBreaker->recordFailure($switch);

        $this->travel(301)->seconds();

        $this->assertTrue($circuitBreaker->isAvailable($switch));

        $circuitBreaker->recordFailure($switch);

        $this->assertFalse($circuitBreaker->isAvailable($switch));

        $this->travel(301)->seconds();

        $this->assertTrue($circuitBreaker->isAvailable($switch));
    }

    public function test_states_and_transitions_are_explicit(): void
    {
        Event::fake([SwitchUnreachable::class]);

        config(['aperture.circuit_breaker.cooldown' => 300]);
        $circuitBreaker = new CircuitBreaker;
        $switch = SwitchConfig::factory()->create();

        $this->assertSame(CircuitState::Closed, $circuitBreaker->state($switch));

        $circuitBreaker->recordFailure($switch);
        $circuitBreaker->recordFailure($switch);
        $this->assertSame(CircuitState::Closed, $circuitBreaker->state($switch));

        $circuitBreaker->recordFailure($switch);
        $this->assertSame(CircuitState::Open, $circuitBreaker->state($switch));

        $this->travel(299)->seconds();
        $this->assertSame(CircuitState::Open, $circuitBreaker->state($switch));

        $this->travel(2)->seconds();
        $this->assertSame(CircuitState::HalfOpen, $circuitBreaker->state($switch));
        $this->assertTrue($circuitBreaker->isAvailable($switch));
    }

    public function test_half_open_closes_on_success(): void
    {
        Event::fake([SwitchUnreachable::class]);

        $circuitBreaker = new CircuitBreaker;
        $switch = SwitchConfig::factory()->create();

        foreach (range(1, 3) as $ignored) {
            $circuitBreaker->recordFailure($switch);
        }

        $this->travel(301)->seconds();
        $this->assertSame(CircuitState::HalfOpen, $circuitBreaker->state($switch));

        $circuitBreaker->recordSuccess($switch);

        $this->assertSame(CircuitState::Closed, $circuitBreaker->state($switch));

        $circuitBreaker->recordFailure($switch);
        $this->assertSame(CircuitState::Closed, $circuitBreaker->state($switch));
    }

    public function test_half_open_reopens_on_single_failure_without_new_event(): void
    {
        Event::fake([SwitchUnreachable::class]);

        $circuitBreaker = new CircuitBreaker;
        $switch = SwitchConfig::factory()->create();

        foreach (range(1, 3) as $ignored) {
            $circuitBreaker->recordFailure($switch);
        }

        $this->travel(301)->seconds();
        $this->assertSame(CircuitState::HalfOpen, $circuitBreaker->state($switch));

        $circuitBreaker->recordFailure($switch);

        $this->assertSame(CircuitState::Open, $circuitBreaker->state($switch));
        $this->assertFalse($circuitBreaker->isAvailable($switch));
        Event::assertDispatchedTimes(SwitchUnreachable::class, 1);
    }

    public function test_default_cooldown_is_300_seconds(): void
    {
        Event::fake([SwitchUnreachable::class]);

        config(['aperture.circuit_breaker' => ['failure_threshold' => 3]]);
        $circuitBreaker = new CircuitBreaker;

        $switch = SwitchConfig::factory()->create();

        $circuitBreaker->recordFailure($switch);
        $circuitBreaker->recordFailure($switch);
        $circuitBreaker->recordFailure($switch);

        $this->travel(299)->seconds();
        $this->assertFalse($circuitBreaker->isAvailable($switch));

        $this->travel(2)->seconds();
        $this->assertTrue($circuitBreaker->isAvailable($switch));
    }
}
