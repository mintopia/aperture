<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\CapabilityAssignment;
use App\Models\IntegrationConfig;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
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

    public function test_admin_can_view_integrations_settings(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/settings/integrations');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/Integrations')
            ->has('services')
        );
    }

    public function test_integrations_page_returns_table_data(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $serviceCount = count(config('integrations'));

        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opn.local');
        CapabilityAssignment::assign('dhcp', 'opnsense');

        $response = $this->actingAs($admin)->get('/admin/settings/integrations');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/Integrations')
            ->has('services', $serviceCount)
            ->where('services.'.($serviceCount - 1).'.id', 'cisco')
            ->has('services.'.($serviceCount - 1).'.capabilities')
        );
    }

    public function test_non_admin_cannot_access_integrations(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin/settings/integrations');

        $response->assertForbidden();
    }
}
