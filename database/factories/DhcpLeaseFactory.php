<?php

namespace Database\Factories;

use App\Models\DhcpLease;
use App\Models\IpAddress;
use App\Models\MacAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DhcpLease> */
class DhcpLeaseFactory extends Factory
{
    protected $model = DhcpLease::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ip_address_id' => IpAddress::factory(),
            'mac_address_id' => MacAddress::factory(),
            'hostname' => fake()->domainWord(),
            'expires_at' => now()->addHours(24),
        ];
    }
}
