<?php

namespace Tests\Feature\Admin;

use App\Models\IpAddress;
use App\Models\Role;
use App\Models\User;
use App\Services\Dhcp\NullDhcpService;
use App\Services\Interfaces\DhcpInterface;
use App\Services\ValueObjects\DhcpRange;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

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
            ->missing('uniqueIps')
            ->missing('recentUsers')
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('dhcpPools')
                ->has('uniqueIps')
                ->has('recentUsers')
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

        $this->app->instance(DhcpInterface::class, new class extends NullDhcpService
        {
            /** @return Collection<int, DhcpRange> */
            public function getRanges(): Collection
            {
                return collect([
                    new DhcpRange(
                        interface: 'lan',
                        type: 'ipv4',
                        subnet: '10.0.0.0/24',
                        rangeFrom: '10.0.0.10',
                        rangeTo: '10.0.0.200',
                        prefix: null,
                        gateway: '10.0.0.1',
                        description: 'Main Pool',
                        totalAddresses: 190,
                        usedAddresses: 25,
                        utilisation: 0.1316,
                    ),
                ]);
            }
        });

        $response = $this->actingAs($user)->get('/admin');

        $response->assertInertia(fn ($page) => $page
            ->missing('dhcpPools')
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('dhcpPools', 1)
                ->where('dhcpPools.0.name', 'Main Pool')
                ->where('dhcpPools.0.network', '10.0.0.0/24')
                ->where('dhcpPools.0.used', 25)
                ->where('dhcpPools.0.total', 190)
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
}
