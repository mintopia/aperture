<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AuditLogControllerTest extends TestCase
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
}
