<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\CapabilityAssignment;
use App\Models\DhcpLease;
use App\Models\DhcpRangeRecord;
use App\Models\DhcpSyncState;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DhcpControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function createAdminUser(): User
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Admin';
        $role->save();
        $user->roles()->attach($role);

        return $user;
    }

    public function test_index_page_renders_ranges_from_database(): void
    {
        CapabilityAssignment::factory()->create([
            'capability' => 'dhcp',
            'integration' => 'vyos',
        ]);

        DhcpRangeRecord::factory()->create([
            'integration' => 'vyos',
            'interface' => 'eth0',
            'type' => 'ipv4',
            'subnet' => '192.168.1.0/24',
            'range_from' => '192.168.1.100',
            'range_to' => '192.168.1.200',
            'used_addresses' => '30',
            'total_addresses' => '101',
            'utilisation' => '0.2970',
        ]);

        $admin = $this->createAdminUser();
        $response = $this->actingAs($admin)->get('/admin/dhcp');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Dhcp/Index')
            ->has('ranges', 1)
            ->where('ranges.0.name', 'eth0')
            ->where('ranges.0.ip_version', 'IPv4')
            ->where('ranges.0.network', '192.168.1.0/24')
            ->where('ranges.0.start', '192.168.1.100')
            ->where('ranges.0.end', '192.168.1.200')
            ->where('ranges.0.used', 30)
            ->where('ranges.0.total', 101)
        );
    }

    public function test_leases_page_renders_leases_from_database(): void
    {
        CapabilityAssignment::factory()->create([
            'capability' => 'dhcp',
            'integration' => 'vyos',
        ]);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.50']);
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        DhcpLease::factory()->create([
            'integration' => 'vyos',
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
            'hostname' => 'test-host',
            'expires_at' => '2026-01-01 12:00:00',
        ]);

        $admin = $this->createAdminUser();
        $response = $this->actingAs($admin)->get('/admin/dhcp/leases');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Dhcp/Leases')
            ->has('leases', 1)
            ->where('leases.0.ip', '10.0.0.50')
            ->where('leases.0.hostname', 'test-host')
        );
    }

    public function test_only_active_integration_data_shown(): void
    {
        CapabilityAssignment::factory()->create([
            'capability' => 'dhcp',
            'integration' => 'vyos',
        ]);

        DhcpRangeRecord::factory()->create([
            'integration' => 'vyos',
            'interface' => 'vyos-eth0',
            'subnet' => '10.1.0.0/24',
        ]);

        DhcpRangeRecord::factory()->create([
            'integration' => 'opnsense',
            'interface' => 'opnsense-em0',
            'subnet' => '10.2.0.0/24',
        ]);

        $admin = $this->createAdminUser();
        $response = $this->actingAs($admin)->get('/admin/dhcp');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Dhcp/Index')
            ->has('ranges', 1)
            ->where('ranges.0.name', 'vyos-eth0')
        );
    }

    public function test_index_shows_last_synced_timestamp(): void
    {
        CapabilityAssignment::factory()->create([
            'capability' => 'dhcp',
            'integration' => 'vyos',
        ]);

        $syncedAt = now()->startOfSecond();

        DhcpSyncState::factory()->create([
            'integration' => 'vyos',
            'dataset' => 'ranges',
            'last_success_at' => $syncedAt,
        ]);

        $admin = $this->createAdminUser();
        $response = $this->actingAs($admin)->get('/admin/dhcp');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Dhcp/Index')
            ->where('lastSyncedAt', $syncedAt->toIso8601String())
        );
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->get('/admin/dhcp');
        $response->assertRedirect('/captive');
    }

    public function test_leases_requires_authentication(): void
    {
        $response = $this->get('/admin/dhcp/leases');
        $response->assertRedirect('/captive');
    }
}
