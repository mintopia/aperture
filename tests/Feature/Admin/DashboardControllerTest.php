<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\CapabilityAssignment;
use App\Models\DhcpRangeRecord;
use App\Models\IpAddress;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected bool $seedSetupUser = false;

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

    public function test_admin_can_access_dashboard(): void
    {
        Queue::fake();
        $user = $this->createAdminUser();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Dashboard')
            ->has('totalUsers')
            ->has('onlineUsers')
            ->has('activeIps')
            ->has('blockedUsers')
            ->missing('dhcpPools')
            ->missing('recentUsers')
            ->missing('recentEvents')
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('dhcpPools')
                ->has('recentUsers')
                ->has('recentEvents')
            )
        );
    }

    public function test_non_admin_cannot_access_dashboard(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertForbidden();
    }

    public function test_unauthenticated_user_redirected(): void
    {
        User::factory()->create();

        $response = $this->get('/admin');

        $response->assertRedirect('/captive');
    }

    public function test_dashboard_shows_correct_counts(): void
    {
        Queue::fake();
        $user = $this->createAdminUser();

        User::factory()->count(3)->create();
        User::factory()->internetBlocked()->create();

        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->internet_enabled = true;
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertInertia(fn ($page) => $page
            ->where('totalUsers', 5)
            ->where('activeIps', 1)
            ->where('blockedUsers', 1)
        );
    }

    public function test_dashboard_returns_deferred_dhcp_pools(): void
    {
        Queue::fake();
        $user = $this->createAdminUser();

        CapabilityAssignment::factory()->create([
            'capability' => 'dhcp',
            'integration' => 'cisco',
        ]);

        DhcpRangeRecord::factory()->create([
            'integration' => 'cisco',
            'interface' => 'lan',
            'type' => 'ipv4',
            'subnet' => '10.0.0.0/24',
            'range_from' => '10.0.0.10',
            'range_to' => '10.0.0.200',
            'prefix' => null,
            'gateway' => '10.0.0.1',
            'description' => 'Main Pool',
            'total_addresses' => '190',
            'used_addresses' => '25',
            'utilisation' => '0.1316',
        ]);

        $response = $this->actingAs($user)->get('/admin');

        $response->assertInertia(fn ($page) => $page
            ->missing('dhcpPools')
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('dhcpPools', 1)
                ->where('dhcpPools.0.name', 'Main Pool')
                ->where('dhcpPools.0.network', '10.0.0.0/24')
                ->where('dhcpPools.0.used', 25)
                ->where('dhcpPools.0.total', '190')
                ->where('dhcpPools.0.utilisation', 0.1316)
            )
        );
    }

    public function test_dashboard_returns_deferred_recent_users(): void
    {
        Queue::fake();
        $user = $this->createAdminUser();
        User::factory()->count(3)->create();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertInertia(fn ($page) => $page
            ->missing('recentUsers')
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('recentUsers')
                ->has('recentUsers.data', 4)
            )
        );
    }

    public function test_dashboard_includes_recent_events_deferred_prop(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        for ($i = 0; $i < 15; $i++) {
            AuditLog::record(action: 'switch.unreachable', metadata: ['failure_count' => $i], severity: 'critical');
        }

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Dashboard')
            ->missing('recentEvents')
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('recentEvents', 10)
            )
        );
    }

    public function test_dashboard_recent_events_come_from_audit_logs(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        AuditLog::record(action: 'switch.unreachable', metadata: ['failure_count' => 2], severity: 'critical');

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertInertia(fn ($page) => $page
            ->missing('recentEvents')
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('recentEvents', 1)
                ->where('recentEvents.0.action', 'switch.unreachable')
                ->where('recentEvents.0.severity', 'critical')
                ->has('recentEvents.0.description')
                ->has('recentEvents.0.id')
                ->has('recentEvents.0.created_at')
            )
        );
    }
}
