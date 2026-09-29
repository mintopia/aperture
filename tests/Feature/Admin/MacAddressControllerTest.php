<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\DhcpLease;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\Role;
use App\Models\SwitchPort;
use App\Models\SwitchPortMac;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MacAddressControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function createAdminUser(): User
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Admin';
        $role->save();
        $user->roles()->attach($role);

        return $user;
    }

    public function test_index_loads_with_mac_data(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        MacAddress::factory()->count(3)->create();

        $response = $this->actingAs($admin)->get('/admin/macs');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Macs/Index')
            ->has('macs.data', 3)
        );
    }

    public function test_index_filterable_by_mac_address(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
        MacAddress::factory()->create(['mac_address' => '11:22:33:44:55:66']);

        $response = $this->actingAs($admin)->get('/admin/macs?mac=AA:BB');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('macs.data', 1));
    }

    public function test_index_filterable_by_hostname(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $mac = MacAddress::factory()->create();
        $ip = IpAddress::factory()->create();
        DhcpLease::factory()->create([
            'mac_address_id' => $mac->id,
            'ip_address_id' => $ip->id,
            'hostname' => 'unique-hostname-xyz',
        ]);
        MacAddress::factory()->create();

        $response = $this->actingAs($admin)->get('/admin/macs?hostname=unique-hostname');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('macs.data', 1));
    }

    public function test_index_filterable_by_ip_address(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $mac = MacAddress::factory()->create();
        $ip = IpAddress::factory()->create(['address' => '10.99.88.77']);
        $mac->ipAddresses()->attach($ip, ['source' => 'arp', 'last_seen_at' => now()]);

        MacAddress::factory()->create();

        $response = $this->actingAs($admin)->get('/admin/macs?ip=10.99.88');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('macs.data', 1));
    }

    public function test_index_filterable_by_user_nickname(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $user = User::factory()->create(['nickname' => 'TargetNickname']);
        MacAddress::factory()->create(['user_id' => $user->id]);
        MacAddress::factory()->create();

        $response = $this->actingAs($admin)->get('/admin/macs?nickname=TargetNick');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('macs.data', 1));
    }

    public function test_index_filterable_by_source(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        MacAddress::factory()->create(['mac_address' => 'AA:AA:AA:AA:AA:AA', 'source' => 'static']);
        MacAddress::factory()->create(['mac_address' => 'BB:BB:BB:BB:BB:BB', 'source' => 'dhcp']);

        $response = $this->actingAs($admin)->get('/admin/macs?source=static');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('macs.data', 1)
            ->where('macs.data.0.source', 'static')
            ->where('filters.source', 'static')
        );
    }

    public function test_index_search_matches_mac_hostname_or_user(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $byMac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:00:00:01']);

        $byHostMac = MacAddress::factory()->create(['mac_address' => '11:22:33:00:00:02']);
        $ip = IpAddress::factory()->create();
        DhcpLease::factory()->create([
            'mac_address_id' => $byHostMac->id,
            'ip_address_id' => $ip->id,
            'hostname' => 'needle-host',
        ]);

        $user = User::factory()->create(['nickname' => 'NeedleUser']);
        $byUserMac = MacAddress::factory()->create(['mac_address' => '99:88:77:00:00:03', 'user_id' => $user->id]);

        MacAddress::factory()->create(['mac_address' => 'FF:FF:FF:00:00:09']);

        $byMacResponse = $this->actingAs($admin)->get('/admin/macs?search=AA:BB:CC');
        $byMacResponse->assertOk();
        $byMacResponse->assertInertia(fn ($page) => $page
            ->has('macs.data', 1)
            ->where('macs.data.0.id', $byMac->id)
            ->where('filters.search', 'AA:BB:CC')
        );

        $byHostResponse = $this->actingAs($admin)->get('/admin/macs?search=needle-host');
        $byHostResponse->assertOk();
        $byHostResponse->assertInertia(fn ($page) => $page
            ->has('macs.data', 1)
            ->where('macs.data.0.id', $byHostMac->id)
        );

        $byUserResponse = $this->actingAs($admin)->get('/admin/macs?search=NeedleUser');
        $byUserResponse->assertOk();
        $byUserResponse->assertInertia(fn ($page) => $page
            ->has('macs.data', 1)
            ->where('macs.data.0.id', $byUserMac->id)
        );
    }

    public function test_index_sortable_by_mac_address(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        MacAddress::factory()->create(['mac_address' => 'BB:BB:BB:BB:BB:BB']);
        MacAddress::factory()->create(['mac_address' => 'AA:AA:AA:AA:AA:AA']);

        $response = $this->actingAs($admin)->get('/admin/macs?order=mac_address&direction=asc');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('macs.data.0.mac_address', 'AA:AA:AA:AA:AA:AA')
            ->where('macs.data.1.mac_address', 'BB:BB:BB:BB:BB:BB')
            ->where('filters.order', 'mac_address')
            ->where('filters.direction', 'asc')
        );
    }

    public function test_index_sortable_descending(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        MacAddress::factory()->create(['mac_address' => 'AA:AA:AA:AA:AA:AA']);
        MacAddress::factory()->create(['mac_address' => 'BB:BB:BB:BB:BB:BB']);

        $response = $this->actingAs($admin)->get('/admin/macs?order=mac_address&direction=desc');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('macs.data.0.mac_address', 'BB:BB:BB:BB:BB:BB')
            ->where('macs.data.1.mac_address', 'AA:AA:AA:AA:AA:AA')
            ->where('filters.order', 'mac_address')
            ->where('filters.direction', 'desc')
        );
    }

    public function test_index_ignores_invalid_order_and_direction(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        MacAddress::factory()->create();

        $response = $this->actingAs($admin)->get('/admin/macs?order=invalid&direction=sideways');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('filters.order', 'created_at')
            ->where('filters.direction', 'desc')
        );
    }

    public function test_index_pagination_works(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        MacAddress::factory()->count(25)->create();

        $response = $this->actingAs($admin)->get('/admin/macs?perPage=10');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('macs.data', 10));
    }

    public function test_index_page_2_with_empty_filter_params(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        MacAddress::factory()->count(25)->create();

        // Pagination links generated via appends() include present-but-empty
        // filter params, which ConvertEmptyStringsToNull converts to null.
        $response = $this->actingAs($admin)->get('/admin/macs?page=2&mac=&hostname=&nickname=&ip=');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Macs/Index')
            ->has('macs.data', 5)
        );
    }

    public function test_show_page_loads_with_all_sections(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $mac = MacAddress::factory()->create();
        $ip = IpAddress::factory()->create();
        $mac->ipAddresses()->attach($ip, ['source' => 'arp', 'last_seen_at' => now()]);

        DhcpLease::factory()->create([
            'mac_address_id' => $mac->id,
            'ip_address_id' => $ip->id,
            'hostname' => 'test-host',
        ]);

        $switchPort = SwitchPort::factory()->create();
        SwitchPortMac::factory()->create([
            'switch_port_id' => $switchPort->id,
            'mac_address' => $mac->mac_address,
            'mac_address_id' => $mac->id,
        ]);

        AuditLog::factory()->create([
            'subject_type' => $mac->getMorphClass(),
            'subject_id' => $mac->id,
        ]);

        $response = $this->actingAs($admin)->get('/admin/macs/'.$mac->mac_address);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Macs/Show')
            ->has('mac')
            ->has('ipAddresses')
            ->has('dhcpLeases')
            ->has('switchPorts')
            ->has('auditLogs')
        );
    }

    public function test_show_uses_mac_address_route_key(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        $response = $this->actingAs($admin)->get('/admin/macs/AA:BB:CC:DD:EE:FF');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Macs/Show')
            ->where('mac.mac_address', 'AA:BB:CC:DD:EE:FF')
        );
    }

    public function test_non_admin_cannot_access_index(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin/macs');

        $response->assertForbidden();
    }

    public function test_non_admin_cannot_access_show(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $mac = MacAddress::factory()->create();

        $response = $this->actingAs($user)->get('/admin/macs/'.$mac->mac_address);

        $response->assertForbidden();
    }

    public function test_show_auto_creates_mac_that_does_not_exist(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $this->assertNull(MacAddress::where('mac_address', 'AA:BB:CC:DD:EE:FF')->first());

        $response = $this->actingAs($admin)->get('/admin/macs/AA:BB:CC:DD:EE:FF');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Macs/Show'));

        $mac = MacAddress::where('mac_address', 'AA:BB:CC:DD:EE:FF')->first();
        $this->assertNotNull($mac);
        $this->assertSame('discovery', $mac->source);
    }
}
