<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SystemEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SystemEvent> */
class SystemEventFactory extends Factory
{
    protected $model = SystemEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => 'UserConnected',
            'level' => 'info',
            'message' => $this->faker->sentence(),
            'data' => ['ip_address' => $this->faker->ipv4()],
        ];
    }

    public function info(): static
    {
        return $this->state(['level' => 'info']);
    }

    public function warning(): static
    {
        return $this->state(['level' => 'warning']);
    }

    public function critical(): static
    {
        return $this->state(['level' => 'critical']);
    }

    public function userConnected(): static
    {
        return $this->state([
            'type' => 'UserConnected',
            'level' => 'info',
            'message' => 'User connected from '.$this->faker->ipv4(),
        ]);
    }

    public function switchUnreachable(): static
    {
        return $this->state([
            'type' => 'SwitchUnreachable',
            'level' => 'critical',
            'message' => 'Switch '.$this->faker->domainWord().' unreachable after 3 failures',
        ]);
    }

    public function dhcpPoolThreshold(): static
    {
        return $this->state([
            'type' => 'DhcpPoolThresholdReached',
            'level' => 'warning',
            'message' => 'DHCP pool LAN reached 90% utilisation',
        ]);
    }
}
