<?php

namespace Database\Factories;

use App\Models\IpAddress;
use App\Models\IpAddressMacAddress;
use App\Models\MacAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IpAddressMacAddress> */
class IpAddressMacAddressFactory extends Factory
{
    protected $model = IpAddressMacAddress::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ip_address_id' => IpAddress::factory(),
            'mac_address_id' => MacAddress::factory(),
            'source' => 'dhcp',
            'last_seen_at' => now(),
        ];
    }
}
