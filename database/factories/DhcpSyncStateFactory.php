<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DhcpSyncState;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DhcpSyncState> */
class DhcpSyncStateFactory extends Factory
{
    protected $model = DhcpSyncState::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'integration' => 'cisco',
            'address_family' => 'ipv4',
            'dataset' => 'leases',
            'empty_count' => 0,
        ];
    }
}
