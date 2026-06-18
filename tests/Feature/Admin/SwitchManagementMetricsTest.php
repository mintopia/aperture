<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\SwitchConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SwitchManagementMetricsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $user;

    private SwitchConfig $switchConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createAdminUser();
        $this->switchConfig = SwitchConfig::factory()->create([
            'hostname' => 'switch1.example.com',
        ]);
    }

    public function test_show_does_not_include_bandwidth_data(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('admin.switches.show', $this->switchConfig));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->missing('bandwidth')
            ->missing('metricsAvailable')
        );
    }

    public function test_show_page_loads_without_metrics_props(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('admin.switches.show', $this->switchConfig));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Show')
            ->has('switchConfig')
            ->missing('bandwidth')
            ->missing('metricsAvailable')
        );
    }

    protected function createAdminUser(): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('code', 'admin')->first();

        if (! $role instanceof Role) {
            $role = new Role;
            $role->code = 'admin';
            $role->name = 'Admin';
            $role->save();
        }

        $user->roles()->attach($role);

        return $user;
    }
}
