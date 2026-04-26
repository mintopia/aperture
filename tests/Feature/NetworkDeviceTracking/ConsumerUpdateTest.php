<?php

declare(strict_types=1);

namespace Tests\Feature\NetworkDeviceTracking;

use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\Role;
use App\Models\User;
use App\Services\LibreNms\LibreNmsService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class ConsumerUpdateTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_dashboard_uses_current_mac(): void
    {
        Queue::fake();
        $user = User::factory()->create(['internet_blocked' => false]);
        $ip = IpAddress::factory()->create(['address' => '127.0.0.1']);
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
        $ip->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);

        $this->mock(LibreNmsService::class, function (MockInterface $mock): void {
            $mock->allows(['getIpv6Neighbors' => collect()]);
        });

        $response = $this->actingAs($user)->get(route('portal.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('blockContext.macAddress', 'AA:BB:CC:DD:EE:FF')
        );
    }

    public function test_dashboard_mac_is_null_when_no_mac_linked(): void
    {
        Queue::fake();
        $user = User::factory()->create(['internet_blocked' => false]);

        $this->mock(LibreNmsService::class, function (MockInterface $mock): void {
            $mock->allows(['getIpv6Neighbors' => collect()]);
        });

        $response = $this->actingAs($user)->get(route('portal.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('blockContext.macAddress', null)
        );
    }

    public function test_ip_show_includes_current_mac(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Admin';
        $role->save();
        $user->roles()->attach($role);

        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();
        $ip->macAddresses()->attach($mac, ['source' => 'dhcp', 'last_seen_at' => now()]);

        $response = $this->actingAs($user)->get(route('admin.ips.show', $ip));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('ip.current_mac.id', $mac->id)
            ->where('ip.current_mac.mac_address', $mac->mac_address)
        );
    }
}
