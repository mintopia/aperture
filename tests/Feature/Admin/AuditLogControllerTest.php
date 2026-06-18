<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\Role;
use App\Models\SwitchConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AuditLogControllerTest extends TestCase
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

    public function test_index_loads(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');

        $response = $this->actingAs($admin)->get('/admin/audit-log');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->has('logs.data', 1)
        );
    }

    public function test_index_includes_human_readable_description(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create(['address' => '10.30.0.1']);
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network', metadata: ['source' => 'dhcp']);

        $response = $this->actingAs($admin)->get('/admin/audit-log');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->where('logs.data.0.description', 'Discovered new IP 10.30.0.1 via dhcp')
        );
    }

    public function test_filterable_by_action(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');
        AuditLog::record(action: 'ip.updated', subject: $ip, process: 'scan_network');

        $response = $this->actingAs($admin)->get('/admin/audit-log?action=ip.created');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->has('logs.data', 1)
        );
    }

    public function test_filterable_by_process(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'admin');

        $response = $this->actingAs($admin)->get('/admin/audit-log?process=scan_network');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->has('logs.data', 1)
        );
    }

    public function test_filterable_by_subject_type(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();
        $target = User::factory()->create();
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');
        AuditLog::record(action: 'user.updated', subject: $target, process: 'admin');

        $response = $this->actingAs($admin)->get('/admin/audit-log?subject_type='.urlencode($ip->getMorphClass()));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->has('logs.data', 1)
            ->where('logs.data.0.subject_type', 'IpAddress')
        );
    }

    public function test_filterable_by_date_from(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();

        $this->travelTo('2026-06-01 12:00:00');
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');
        $this->travelTo('2026-06-05 12:00:00');
        AuditLog::record(action: 'ip.updated', subject: $ip, process: 'scan_network');
        $this->travelBack();

        $response = $this->actingAs($admin)->get('/admin/audit-log?date_from=2026-06-03');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->has('logs.data', 1)
            ->where('logs.data.0.action', 'ip.updated')
        );
    }

    public function test_filterable_by_date_to(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();

        $this->travelTo('2026-06-01 12:00:00');
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');
        $this->travelTo('2026-06-05 12:00:00');
        AuditLog::record(action: 'ip.updated', subject: $ip, process: 'scan_network');
        $this->travelBack();

        $response = $this->actingAs($admin)->get('/admin/audit-log?date_to=2026-06-03');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->has('logs.data', 1)
            ->where('logs.data.0.action', 'ip.created')
        );
    }

    public function test_pagination_works(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();

        for ($i = 0; $i < 25; $i++) {
            AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');
        }

        $response = $this->actingAs($admin)->get('/admin/audit-log?perPage=10');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->has('logs.data', 10)
        );
    }

    public function test_non_admin_cannot_access(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/audit-log')->assertForbidden();
    }

    public function test_subject_url_resolved_for_user(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $target = User::factory()->create();
        AuditLog::record(action: 'user.updated', subject: $target, actor: $admin, process: 'admin');

        $response = $this->actingAs($admin)->get('/admin/audit-log');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->where('logs.data.0.subject_type', 'User')
            ->where('logs.data.0.subject_url', route('admin.users.show', $target))
        );
    }

    public function test_subject_url_resolved_for_ip_address(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');

        $response = $this->actingAs($admin)->get('/admin/audit-log');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->where('logs.data.0.subject_type', 'IpAddress')
            ->where('logs.data.0.subject_url', route('admin.ips.show', $ip))
        );
    }

    public function test_subject_url_resolved_for_mac_address(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $mac = MacAddress::factory()->create();
        AuditLog::record(action: 'mac.created', subject: $mac, process: 'scan_network');

        $response = $this->actingAs($admin)->get('/admin/audit-log');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->where('logs.data.0.subject_type', 'MacAddress')
            ->where('logs.data.0.subject_url', route('admin.macs.show', $mac))
        );
    }

    public function test_subject_url_resolved_for_switch_config(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();
        AuditLog::record(action: 'switch.updated', subject: $switch, process: 'admin');

        $response = $this->actingAs($admin)->get('/admin/audit-log');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->where('logs.data.0.subject_type', 'SwitchConfig')
            ->where('logs.data.0.subject_url', route('admin.switches.show', $switch))
        );
    }

    public function test_related_url_resolved_when_present(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();
        $target = User::factory()->create();
        AuditLog::record(action: 'ip.assigned', subject: $ip, related: $target, process: 'admin');

        $response = $this->actingAs($admin)->get('/admin/audit-log');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->where('logs.data.0.related_type', 'User')
            ->where('logs.data.0.related_url', route('admin.users.show', $target))
        );
    }

    public function test_actor_url_resolved_for_admin_user(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();
        AuditLog::record(action: 'ip.created', subject: $ip, actor: $admin, process: 'admin');

        $response = $this->actingAs($admin)->get('/admin/audit-log');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->where('logs.data.0.actor_type', 'User')
            ->where('logs.data.0.actor_url', route('admin.users.show', $admin))
        );
    }

    public function test_urls_are_null_when_no_related_or_actor(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');

        $response = $this->actingAs($admin)->get('/admin/audit-log');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->where('logs.data.0.related_url', null)
            ->where('logs.data.0.actor_url', null)
        );
    }

    public function test_sortable_by_action_ascending(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();
        AuditLog::record(action: 'ip.updated', subject: $ip, process: 'scan_network');
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');

        $response = $this->actingAs($admin)->get('/admin/audit-log?order=action&direction=asc');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->where('logs.data.0.action', 'ip.created')
            ->where('logs.data.1.action', 'ip.updated')
            ->where('filters.order', 'action')
            ->where('filters.direction', 'asc')
        );
    }

    public function test_sortable_by_action_descending(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');
        AuditLog::record(action: 'ip.updated', subject: $ip, process: 'scan_network');

        $response = $this->actingAs($admin)->get('/admin/audit-log?order=action&direction=desc');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->where('logs.data.0.action', 'ip.updated')
            ->where('logs.data.1.action', 'ip.created')
        );
    }

    public function test_defaults_to_created_at_desc_sort(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/audit-log');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('filters.order', 'created_at')
            ->where('filters.direction', 'desc')
        );
    }

    public function test_ignores_invalid_sort_column(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/audit-log?order=invalid_column&direction=asc');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('filters.order', 'created_at')
            ->where('filters.direction', 'asc')
        );
    }

    public function test_ignores_invalid_sort_direction(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/audit-log?order=action&direction=invalid');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('filters.order', 'action')
            ->where('filters.direction', 'desc')
        );
    }

    public function test_sortable_by_process(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $ip = IpAddress::factory()->create();
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'admin');

        $response = $this->actingAs($admin)->get('/admin/audit-log?order=process&direction=asc');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('logs.data.0.process', 'admin')
            ->where('logs.data.1.process', 'scan_network')
        );
    }
}
