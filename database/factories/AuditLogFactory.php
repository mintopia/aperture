<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\IpAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditLog> */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $ip = IpAddress::factory()->create();

        return [
            'action' => 'ip.created',
            'subject_type' => $ip->getMorphClass(),
            'subject_id' => $ip->id,
            'process' => 'scan_network',
        ];
    }
}
