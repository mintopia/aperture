<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IpAddress;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserAddIpManagedRangeTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Test]
    public function add_ip_returns_ip_when_in_managed_range(): void
    {
        Queue::fake();
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['10.0.0.0/8']));

        $user = User::factory()->create();
        $result = $user->addIp('10.0.0.1');

        $this->assertInstanceOf(IpAddress::class, $result);
        $this->assertSame('10.0.0.1', $result->address);
        $this->assertDatabaseHas('ip_addresses', ['address' => '10.0.0.1']);
        $this->assertDatabaseHas('user_ip_addresses', ['user_id' => $user->id]);
    }

    #[Test]
    public function add_ip_returns_null_when_outside_managed_range(): void
    {
        Queue::fake();
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['10.0.0.0/8']));

        $user = User::factory()->create();
        $result = $user->addIp('192.168.1.1');

        $this->assertNull($result);
        $this->assertDatabaseMissing('ip_addresses', ['address' => '192.168.1.1']);
        $this->assertDatabaseMissing('user_ip_addresses', ['user_id' => $user->id]);
    }

    #[Test]
    public function add_ip_manages_all_when_no_settings_exist(): void
    {
        Queue::fake();
        // No settings in DB — defaults to 0.0.0.0/0 and ::/0
        $user = User::factory()->create();
        $result = $user->addIp('203.0.113.50');

        $this->assertInstanceOf(IpAddress::class, $result);
        $this->assertSame('203.0.113.50', $result->address);
    }

    #[Test]
    public function add_ip_denies_all_when_ranges_empty(): void
    {
        Queue::fake();
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode([]));

        $user = User::factory()->create();
        $result = $user->addIp('10.0.0.1');

        $this->assertNull($result);
    }
}
