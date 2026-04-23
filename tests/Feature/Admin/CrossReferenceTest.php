<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\DhcpLease;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\Role;
use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Models\SwitchPortMac;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CrossReferenceTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_ip_show_includes_mac_associations(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();
        $ip->macAddresses()->attach($mac, ['source' => 'dhcp', 'last_seen_at' => now()]);

        $response = $this->actingAs($admin)->get(route('admin.ips.show', $ip));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('macAddresses', 1)
        );
    }

    public function test_ip_show_includes_dhcp_leases(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();
        DhcpLease::factory()->create(['ip_address_id' => $ip->id, 'mac_address_id' => $mac->id]);

        $response = $this->actingAs($admin)->get(route('admin.ips.show', $ip));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('dhcpLeases', 1)
        );
    }

    public function test_ip_show_includes_audit_logs(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');

        $response = $this->actingAs($admin)->get(route('admin.ips.show', $ip));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('auditLogs', 1)
        );
    }

    public function test_user_show_includes_mac_addresses(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create();
        MacAddress::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($admin)->get(route('admin.users.show', $user));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('macAddresses', 1)
        );
    }

    public function test_user_show_includes_audit_logs(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create();
        AuditLog::record(action: 'user.login', subject: $user, process: 'portal_login');

        $response = $this->actingAs($admin)->get(route('admin.users.show', $user));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('auditLogs', 1)
        );
    }

    public function test_switch_port_show_uses_db_relationships(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $switchConfig = SwitchConfig::factory()->create();
        $port = SwitchPort::factory()->create(['switch_config_id' => $switchConfig->id]);
        $mac = MacAddress::factory()->create();
        SwitchPortMac::factory()->create([
            'switch_port_id' => $port->id,
            'mac_address' => $mac->mac_address,
            'mac_address_id' => $mac->id,
        ]);

        $response = $this->actingAs($admin)->get(
            route('admin.switches.ports.show', [$switchConfig, $port->port_name])
        );

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('macs', 1)
        );
    }
}
