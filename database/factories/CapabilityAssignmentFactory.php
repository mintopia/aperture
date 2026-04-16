<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CapabilityAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CapabilityAssignment>
 */
class CapabilityAssignmentFactory extends Factory
{
    protected $model = CapabilityAssignment::class;

    public function definition(): array
    {
        return [
            'capability' => fake()->unique()->randomElement([
                'captive-portal', 'firewall', 'rate-limiting', 'dhcp',
                'ip-to-mac', 'mac-to-port', 'port-bandwidth', 'device-list',
                'user-bandwidth', 'top-talkers', 'aggregate-stats',
                'dns-filtering',
            ]),
            'integration' => fake()->randomElement(['opnsense', 'librenms', 'ntopng', 'pihole']),
        ];
    }
}
