<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\CapabilityAssignment;
use App\Models\DhcpRangeRecord;
use App\Models\Role;
use App\Models\User;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Null\NullDhcpService;
use App\Services\ValueObjects\DhcpRange;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class HomeControllerDhcpPoolsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected bool $seedSetupUser = false;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        // The dashboard must never query the DHCP provider live; pools come
        // from the synced dhcp_range_records table. Any call to getRanges()
        // fails the test.
        $this->app->instance(DhcpInterface::class, new class extends NullDhcpService
        {
            /** @return Collection<int, DhcpRange> */
            public function getRanges(): Collection
            {
                throw new RuntimeException('Dashboard must not query the DHCP provider live.');
            }
        });
    }

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

    public function test_dashboard_dhcp_pools_come_from_synced_range_records(): void
    {
        $admin = $this->createAdminUser();

        CapabilityAssignment::factory()->create([
            'capability' => 'dhcp',
            'integration' => 'cisco',
        ]);

        DhcpRangeRecord::factory()->create([
            'integration' => 'cisco',
            'interface' => 'Vlan100',
            'description' => 'Main LAN',
            'subnet' => '10.0.0.0/24',
            'total_addresses' => '191',
            'used_addresses' => '50',
            'utilisation' => '0.2618',
        ]);

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Dashboard')
            ->missing('dhcpPools')
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('dhcpPools', 1)
                ->where('dhcpPools.0.name', 'Main LAN')
                ->where('dhcpPools.0.network', '10.0.0.0/24')
                ->where('dhcpPools.0.used', 50)
                // total is an exact decimal numeric string end-to-end
                ->where('dhcpPools.0.total', '191')
                ->where('dhcpPools.0.utilisation', 0.2618)
            )
        );
    }

    public function test_dashboard_dhcp_pools_pass_huge_ipv6_totals_as_exact_strings(): void
    {
        $admin = $this->createAdminUser();

        CapabilityAssignment::factory()->create([
            'capability' => 'dhcp',
            'integration' => 'cisco',
        ]);

        DhcpRangeRecord::factory()->create([
            'integration' => 'cisco',
            'interface' => 'VLAN400_DHCPV6',
            'type' => 'ipv6',
            'description' => 'V6 LAN',
            'subnet' => '',
            'prefix' => '2a0f:85c1:d91:2100::/64',
            'range_from' => '',
            'range_to' => '',
            'gateway' => null,
            // 2^64 — beyond PHP_INT_MAX; an (int) cast would corrupt it
            'total_addresses' => '18446744073709551616',
            'used_addresses' => '3',
            'utilisation' => null,
        ]);

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('dhcpPools', 1)
                ->where('dhcpPools.0.name', 'V6 LAN')
                ->where('dhcpPools.0.used', 3)
                ->where('dhcpPools.0.total', '18446744073709551616')
            )
        );
    }

    public function test_dashboard_dhcp_pools_only_include_active_integration(): void
    {
        $admin = $this->createAdminUser();

        CapabilityAssignment::factory()->create([
            'capability' => 'dhcp',
            'integration' => 'cisco',
        ]);

        DhcpRangeRecord::factory()->create([
            'integration' => 'cisco',
            'description' => 'Cisco Pool',
            'subnet' => '10.0.0.0/24',
        ]);

        DhcpRangeRecord::factory()->create([
            'integration' => 'opnsense',
            'description' => 'OPNsense Pool',
            'subnet' => '192.168.1.0/24',
        ]);

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('dhcpPools', 1)
                ->where('dhcpPools.0.name', 'Cisco Pool')
            )
        );
    }

    public function test_dashboard_dhcp_pools_apply_fallbacks_for_missing_values(): void
    {
        $admin = $this->createAdminUser();

        CapabilityAssignment::factory()->create([
            'capability' => 'dhcp',
            'integration' => 'cisco',
        ]);

        DhcpRangeRecord::factory()->create([
            'integration' => 'cisco',
            'interface' => 'Vlan200',
            'type' => 'ipv6',
            'description' => null,
            'subnet' => '',
            'prefix' => '2001:db8::/64',
            'range_from' => '',
            'range_to' => '',
            'gateway' => null,
            'total_addresses' => null,
            'used_addresses' => null,
            'utilisation' => null,
        ]);

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('dhcpPools', 1)
                ->where('dhcpPools.0.name', 'Vlan200')
                ->where('dhcpPools.0.network', '2001:db8::/64')
                ->where('dhcpPools.0.used', 0)
                // null totals are rendered as the string '0'
                ->where('dhcpPools.0.total', '0')
                // The controller falls back to float 0.0, but AssertableInertia
                // re-encodes the page via json_encode() without
                // JSON_PRESERVE_ZERO_FRACTION, so a zero-fraction float can only
                // ever be observed as int 0 here.
                ->where('dhcpPools.0.utilisation', 0)
            )
        );
    }

    public function test_dashboard_dhcp_pools_empty_when_no_capability_assignment_exists(): void
    {
        $admin = $this->createAdminUser();

        DhcpRangeRecord::factory()->create([
            'integration' => 'cisco',
            'description' => 'Orphaned Pool',
            'subnet' => '10.0.0.0/24',
        ]);

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('dhcpPools', 0)
            )
        );
    }

    public function test_dashboard_dhcp_pools_empty_when_no_records_exist(): void
    {
        $admin = $this->createAdminUser();

        CapabilityAssignment::factory()->create([
            'capability' => 'dhcp',
            'integration' => 'cisco',
        ]);

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('dhcpPools', 0)
            )
        );
    }
}
