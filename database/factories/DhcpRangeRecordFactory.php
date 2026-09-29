<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DhcpRangeRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DhcpRangeRecord> */
class DhcpRangeRecordFactory extends Factory
{
    protected $model = DhcpRangeRecord::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'integration' => 'cisco',
            'interface' => 'Vlan100',
            'type' => 'ipv4',
            'subnet' => '10.0.0.0/24',
            'range_from' => '10.0.0.10',
            'range_to' => '10.0.0.200',
            'prefix' => null,
            'gateway' => '10.0.0.1',
            'description' => 'Main LAN',
            'total_addresses' => '191',
            'used_addresses' => '50',
            'utilisation' => '0.2618',
        ];
    }

    public function ipv6(): static
    {
        return $this->state([
            'type' => 'ipv6',
            'subnet' => '2001:db8::/64',
            'range_from' => '2001:db8::10',
            'range_to' => '2001:db8::ff',
            'prefix' => '2001:db8::/64',
            'gateway' => null,
        ]);
    }
}
