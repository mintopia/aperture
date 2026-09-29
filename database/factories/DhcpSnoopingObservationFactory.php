<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DhcpSnoopingObservation;
use App\Models\SwitchConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DhcpSnoopingObservation> */
class DhcpSnoopingObservationFactory extends Factory
{
    protected $model = DhcpSnoopingObservation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'switch_config_id' => SwitchConfig::factory(),
            'vlan' => 100,
            'ip' => '10.0.0.50',
            'mac' => 'AA:BB:CC:DD:EE:FF',
            'observed_at' => now(),
        ];
    }
}
