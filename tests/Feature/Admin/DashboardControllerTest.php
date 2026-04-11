<?php

namespace Tests\Feature\Admin;

use App\Models\IpAddress;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->has('totalIps')
            ->has('allowedIps')
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

        $response->assertRedirect('/login');
    }

    public function test_dashboard_shows_correct_counts(): void
    {
        Queue::fake();
        $user = $this->createAdminUser();

        User::factory()->count(3)->create();

        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->allowed = true;
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertInertia(fn ($page) => $page
            ->where('totalUsers', 4)
            ->where('totalIps', 1)
            ->where('allowedIps', 1)
        );
    }
}
