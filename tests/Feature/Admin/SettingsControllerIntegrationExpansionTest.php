<?php

namespace Tests\Feature\Admin;

use App\Models\IntegrationConfig;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SettingsControllerIntegrationExpansionTest extends TestCase
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
                    && in_array('pihole', $ids)
                    && in_array('prometheus', $ids);
            })
        );
    }

    public function test_integrations_page_uses_explicit_enabled_flag_when_present(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        // Set an explicit enabled=1 flag in the integration config to hit line 77
        // (bool) $config['enabled'] — the isset($config['enabled']) branch
        IntegrationConfig::setValue('prometheus', 'enabled', '1');
        IntegrationConfig::setValue('prometheus', 'endpoint', 'http://prometheus.local:9090');

        $response = $this->actingAs($admin)->get('/admin/settings/integrations');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/Integrations')
            ->where('services', function ($services): bool {
                $prometheus = collect($services)->firstWhere('id', 'prometheus');

                return $prometheus !== null && $prometheus['enabled'] === true;
            })
        );
    }

    public function test_cisco_integration_shows_enabled_when_switch_id_configured(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        // Cisco has no endpoint config key; a configured switch_id should mark it enabled
        IntegrationConfig::setValue('cisco', 'switch_id', '1');

        $response = $this->actingAs($admin)->get('/admin/settings/integrations');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/Integrations')
            ->where('services', function ($services): bool {
                $cisco = collect($services)->firstWhere('id', 'cisco');

                return $cisco !== null && $cisco['enabled'] === true;
            })
        );
    }

    public function test_integrations_page_uses_explicit_enabled_false_when_set_to_zero(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        // Set explicit enabled=0 to cover (bool) $config['enabled'] returning false
        IntegrationConfig::setValue('prometheus', 'enabled', '0');
        IntegrationConfig::setValue('prometheus', 'endpoint', 'http://prometheus.local:9090');

        $response = $this->actingAs($admin)->get('/admin/settings/integrations');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/Integrations')
            ->where('services', function ($services): bool {
                $prometheus = collect($services)->firstWhere('id', 'prometheus');

                return $prometheus !== null && $prometheus['enabled'] === false;
            })
        );
    }
}
