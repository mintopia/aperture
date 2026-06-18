<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\DhcpLease;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\Role;
use App\Models\SwitchConfig;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SearchControllerTest extends TestCase
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

    public function test_search_finds_users_by_nickname(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        User::factory()->create(['nickname' => 'TestPlayer']);

        $response = $this->actingAs($admin)->getJson('/admin/search?q=TestPlayer');

        $response->assertOk();
        $response->assertJsonCount(1, 'users');
        $response->assertJsonPath('users.0.nickname', 'TestPlayer');
    }

    public function test_search_finds_users_by_email(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        User::factory()->create(['email' => 'findme@example.com']);

        $response = $this->actingAs($admin)->getJson('/admin/search?q=findme');

        $response->assertOk();
        $response->assertJsonCount(1, 'users');
    }

    public function test_search_finds_ips_by_address(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = new IpAddress;
        $ip->address = '192.168.1.50';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($admin)->getJson('/admin/search?q=192.168.1.50');

        $response->assertOk();
        $response->assertJsonCount(1, 'ips');
    }

    public function test_search_returns_grouped_results(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->getJson('/admin/search?q=test');

        $response->assertOk();
        $response->assertJsonStructure(['users', 'ips']);
    }

    public function test_search_rejects_short_queries(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->getJson('/admin/search?q=a');

        $response->assertStatus(422);
    }

    public function test_non_admin_cannot_search(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/admin/search?q=test');

        $response->assertForbidden();
    }

    public function test_search_rejects_missing_query(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->getJson('/admin/search');

        $response->assertStatus(422);
    }

    public function test_search_rejects_query_exceeding_max_length(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->getJson('/admin/search?q='.str_repeat('a', 101));

        $response->assertStatus(422);
    }

    public function test_search_escapes_percent_metacharacter(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        User::factory()->create(['nickname' => 'normal_user']);

        // A query of '%' would match everything without escaping; with escaping it matches nothing
        $response = $this->actingAs($admin)->getJson('/admin/search?q=%_');

        $response->assertOk();
        $response->assertJsonCount(0, 'users');
    }

    public function test_search_escapes_underscore_metacharacter(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        // Create a user whose nickname does NOT contain a literal underscore
        User::factory()->create(['nickname' => 'abcde']);

        // '_' without escaping would act as a wildcard and match 'abcde'
        // With escaping it only matches a literal '_', so zero results expected
        $response = $this->actingAs($admin)->getJson('/admin/search?q=___');

        $response->assertOk();
        $response->assertJsonCount(0, 'users');
    }

    public function test_search_finds_mac_addresses_by_address(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        $response = $this->actingAs($admin)->getJson('/admin/search?q=AA:BB:CC');

        $response->assertOk();
        $response->assertJsonCount(1, 'macs');
        $response->assertJsonPath('macs.0.mac_address', 'AA:BB:CC:DD:EE:FF');
    }

    public function test_search_finds_switches_by_name(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        SwitchConfig::factory()->create(['name' => 'Core Switch Alpha']);

        $response = $this->actingAs($admin)->getJson('/admin/search?q=Core Switch');

        $response->assertOk();
        $response->assertJsonCount(1, 'switches');
        $response->assertJsonPath('switches.0.name', 'Core Switch Alpha');
    }

    public function test_search_finds_switches_by_hostname(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        SwitchConfig::factory()->create(['hostname' => 'swcore01.lan']);

        $response = $this->actingAs($admin)->getJson('/admin/search?q=swcore');

        $response->assertOk();
        $response->assertJsonCount(1, 'switches');
        $response->assertJsonPath('switches.0.hostname', 'swcore01.lan');
    }

    public function test_search_finds_dhcp_leases_by_hostname(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        DhcpLease::factory()->create(['hostname' => 'desktopgaming']);

        $response = $this->actingAs($admin)->getJson('/admin/search?q=desktopgaming');

        $response->assertOk();
        $response->assertJsonCount(1, 'dhcp_hostnames');
        $response->assertJsonPath('dhcp_hostnames.0.hostname', 'desktopgaming');
    }

    public function test_search_finds_audit_log_entries_by_action(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        AuditLog::factory()->create(['action' => 'ip.statechange']);

        $response = $this->actingAs($admin)->getJson('/admin/search?q=ip.statechange');

        $response->assertOk();
        $response->assertJsonCount(1, 'audit_logs');
        $response->assertJsonPath('audit_logs.0.action', 'ip.statechange');
    }

    public function test_search_finds_audit_log_entries_by_process(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        AuditLog::factory()->create(['process' => 'linkmacprocess']);

        $response = $this->actingAs($admin)->getJson('/admin/search?q=linkmacprocess');

        $response->assertOk();
        $response->assertJsonCount(1, 'audit_logs');
        $response->assertJsonPath('audit_logs.0.process', 'linkmacprocess');
    }

    public function test_search_returns_all_new_result_groups_in_structure(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->getJson('/admin/search?q=test');

        $response->assertOk();
        $response->assertJsonStructure([
            'users',
            'ips',
            'macs',
            'switches',
            'dhcp_hostnames',
            'audit_logs',
        ]);
    }

    public function test_search_limits_mac_results_to_five(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        for ($i = 0; $i < 7; $i++) {
            MacAddress::factory()->create(['mac_address' => sprintf('AA:BB:CC:DD:E%d:00', $i)]);
        }

        $response = $this->actingAs($admin)->getJson('/admin/search?q=AA:BB:CC');

        $response->assertOk();
        $response->assertJsonCount(5, 'macs');
    }

    public function test_search_limits_switch_results_to_five(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        for ($i = 0; $i < 7; $i++) {
            SwitchConfig::factory()->create(['name' => 'TestSwitchX'.$i]);
        }

        $response = $this->actingAs($admin)->getJson('/admin/search?q=TestSwitchX');

        $response->assertOk();
        $response->assertJsonCount(5, 'switches');
    }

    public function test_search_limits_dhcp_hostname_results_to_five(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        for ($i = 0; $i < 7; $i++) {
            DhcpLease::factory()->create(['hostname' => 'testhostx'.$i]);
        }

        $response = $this->actingAs($admin)->getJson('/admin/search?q=testhostx');

        $response->assertOk();
        $response->assertJsonCount(5, 'dhcp_hostnames');
    }

    public function test_search_limits_audit_log_results_to_five(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        for ($i = 0; $i < 7; $i++) {
            AuditLog::factory()->create(['action' => 'test.actionx'.$i]);
        }

        $response = $this->actingAs($admin)->getJson('/admin/search?q=test.actionx');

        $response->assertOk();
        $response->assertJsonCount(5, 'audit_logs');
    }

    public function test_search_returns_correct_mac_fields(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        MacAddress::factory()->create([
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'description' => 'Test Device',
        ]);

        $response = $this->actingAs($admin)->getJson('/admin/search?q=AA:BB:CC');

        $response->assertOk();
        $response->assertJsonStructure([
            'macs' => [
                ['id', 'mac_address', 'description'],
            ],
        ]);
    }

    public function test_search_returns_correct_switch_fields(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        SwitchConfig::factory()->create([
            'name' => 'TestSwitchY',
            'hostname' => 'sw01.lan',
        ]);

        $response = $this->actingAs($admin)->getJson('/admin/search?q=TestSwitchY');

        $response->assertOk();
        $response->assertJsonStructure([
            'switches' => [
                ['id', 'name', 'hostname'],
            ],
        ]);
    }

    public function test_search_returns_correct_dhcp_hostname_fields(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        DhcpLease::factory()->create(['hostname' => 'testhosty']);

        $response = $this->actingAs($admin)->getJson('/admin/search?q=testhosty');

        $response->assertOk();
        $response->assertJsonStructure([
            'dhcp_hostnames' => [
                ['id', 'hostname', 'mac_address_id'],
            ],
        ]);
    }

    public function test_search_returns_correct_audit_log_fields(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        AuditLog::factory()->create(['action' => 'ip.statechangey']);

        $response = $this->actingAs($admin)->getJson('/admin/search?q=statechangey');

        $response->assertOk();
        $response->assertJsonStructure([
            'audit_logs' => [
                ['id', 'action', 'process', 'created_at'],
            ],
        ]);
    }

    public function test_search_excludes_null_dhcp_hostnames(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        DhcpLease::factory()->create(['hostname' => null]);

        $response = $this->actingAs($admin)->getJson('/admin/search?q=zz');

        $response->assertOk();
        $response->assertJsonCount(0, 'dhcp_hostnames');
    }

    public function test_search_deduplicates_dhcp_hostnames(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $mac = MacAddress::factory()->create();
        DhcpLease::factory()->create(['hostname' => 'duphost', 'mac_address_id' => $mac->id]);
        DhcpLease::factory()->create(['hostname' => 'duphost', 'mac_address_id' => $mac->id]);

        $response = $this->actingAs($admin)->getJson('/admin/search?q=duphost');

        $response->assertOk();
        // Grouped by hostname+mac_address_id, so duplicates are collapsed
        $response->assertJsonCount(1, 'dhcp_hostnames');
    }
}
