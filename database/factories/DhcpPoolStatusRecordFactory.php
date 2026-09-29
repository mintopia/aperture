<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DhcpPoolStatusRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DhcpPoolStatusRecord> */
class DhcpPoolStatusRecordFactory extends Factory
{
    protected $model = DhcpPoolStatusRecord::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'integration' => 'cisco',
            'address_family' => 'ipv4',
            'total' => '254',
            'used' => '50',
            'available' => '204',
            'utilisation' => '0.1969',
            'synced_at' => now(),
        ];
    }

    public function ipv6(): static
    {
        return $this->state([
            'address_family' => 'ipv6',
        ]);
    }
}
