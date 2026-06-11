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
use Illuminate\Support\Facades\Schema;
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
            // total is an exact decimal numeric string end-to-end
            ->where('ranges.0.total', '101')
        );
    }

    public function test_index_passes_huge_ipv6_totals_as_exact_strings(): void
    {
        CapabilityAssignment::factory()->create([
            'capability' => 'dhcp',
            'integration' => 'cisco',
        ]);

        DhcpRangeRecord::factory()->ipv6()->create([
            'integration' => 'cisco',
            'interface' => 'VLAN400_DHCPV6',
            'used_addresses' => '3',
            // 2^64 — beyond PHP_INT_MAX; an (int) cast would corrupt it
            'total_addresses' => '18446744073709551616',
            'utilisation' => '0',
        ]);

        $admin = $this->createAdminUser();
        $response = $this->actingAs($admin)->get('/admin/dhcp');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Dhcp/Index')
            ->has('ranges', 1)
            ->where('ranges.0.used', 3)
            ->where('ranges.0.total', '18446744073709551616')
            ->where('ranges.0.percentage', 0)
        );
    }

    public function test_index_passes_null_usage_fields_when_unknown(): void
    {
        CapabilityAssignment::factory()->create([
            'capability' => 'dhcp',
            'integration' => 'cisco',
        ]);

        // IPv6 pool with a known used count but uncountable total (/64)
        DhcpRangeRecord::factory()->ipv6()->create([
            'integration' => 'cisco',
            'interface' => 'VLAN400_DHCPV6',
            'used_addresses' => '3',
            'total_addresses' => null,
            'utilisation' => null,
        ]);

        // IPv6 pool where usage is entirely unknown
        DhcpRangeRecord::factory()->ipv6()->create([
            'integration' => 'cisco',
            'interface' => 'VLAN440_DHCPV6',
            'used_addresses' => null,
            'total_addresses' => null,
            'utilisation' => null,
        ]);

        $admin = $this->createAdminUser();
        $response = $this->actingAs($admin)->get('/admin/dhcp');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Dhcp/Index')
            ->has('ranges', 2)
            ->where('ranges.0.used', 3)
            ->where('ranges.0.total', null)
            ->where('ranges.0.percentage', null)
            ->where('ranges.1.used', null)
            ->where('ranges.1.total', null)
            ->where('ranges.1.percentage', null)
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

    public function test_leases_shows_mac_from_ip_association_when_lease_has_no_mac(): void
    {
        CapabilityAssignment::factory()->create([
            'capability' => 'dhcp',
            'integration' => 'cisco',
        ]);

        $ip = IpAddress::factory()->create(['address' => '10.30.0.101']);
        $olderMac = MacAddress::factory()->create(['mac_address' => '11:22:33:44:55:66']);
        $newerMac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
        $ip->macAddresses()->attach($olderMac->id, ['source' => 'arp', 'last_seen_at' => now()->subDay()]);
        $ip->macAddresses()->attach($newerMac->id, ['source' => 'arp', 'last_seen_at' => now()]);

        DhcpLease::factory()->create([
            'integration' => 'cisco',
            'ip_address_id' => $ip->id,
            'mac_address_id' => null,
            'hostname' => 'test-host',
        ]);

        $admin = $this->createAdminUser();
        $response = $this->actingAs($admin)->get('/admin/dhcp/leases');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Dhcp/Leases')
            ->has('leases', 1)
            ->where('leases.0.ip', '10.30.0.101')
            ->where('leases.0.mac', 'AA:BB:CC:DD:EE:FF')
        );
    }

    public function test_leases_ranges_include_prefix_and_type(): void
    {
        CapabilityAssignment::factory()->create([
            'capability' => 'dhcp',
            'integration' => 'cisco',
        ]);

        DhcpRangeRecord::factory()->create([
            'integration' => 'cisco',
            'type' => 'ipv4',
            'subnet' => '10.0.0.0/24',
            'range_from' => '10.0.0.10',
            'range_to' => '10.0.0.200',
            'prefix' => null,
        ]);

        DhcpRangeRecord::factory()->create([
            'integration' => 'cisco',
            'interface' => 'Vlan200',
            'type' => 'ipv6',
            'subnet' => '',
            'range_from' => '',
            'range_to' => '',
            'prefix' => '2001:db8:1::/64',
        ]);

        $admin = $this->createAdminUser();
        $response = $this->actingAs($admin)->get('/admin/dhcp/leases');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Dhcp/Leases')
            ->has('ranges', 2)
            ->where('ranges.0.network', '10.0.0.0/24')
            ->where('ranges.0.start', '10.0.0.10')
            ->where('ranges.0.end', '10.0.0.200')
            ->where('ranges.0.prefix', null)
            ->where('ranges.0.type', 'ipv4')
            ->where('ranges.1.network', '2001:db8:1::/64')
            ->where('ranges.1.start', null)
            ->where('ranges.1.end', null)
            ->where('ranges.1.prefix', '2001:db8:1::/64')
            ->where('ranges.1.type', 'ipv6')
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

    public function test_index_renders_when_capability_lookup_fails(): void
    {
        $admin = $this->createAdminUser();

        // Simulate a failing capability lookup (e.g. migration not yet run)
        Schema::drop('capability_assignments');

        $response = $this->actingAs($admin)->get('/admin/dhcp');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Dhcp/Index')
            ->has('ranges', 0)
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
