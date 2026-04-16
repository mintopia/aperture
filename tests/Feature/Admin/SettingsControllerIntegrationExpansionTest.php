<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SettingsControllerIntegrationExpansionTest extends TestCase
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

    public function test_integrations_page_returns_services_table_data(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/settings/integrations');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/Integrations')
            ->has('services')
            ->where('services', function ($services): bool {
                $ids = collect($services)->pluck('id')->all();

                return in_array('borealis', $ids)
                    && in_array('opnsense', $ids)
                    && in_array('librenms', $ids)
                    && in_array('ntopng', $ids)
                    && in_array('pihole', $ids);
            })
        );
    }
}
