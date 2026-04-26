<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ThemeSettingsControllerTest extends TestCase
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

    public function test_old_theme_route_no_longer_exists(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $this->actingAs($admin)->get('/admin/settings/theme')->assertNotFound();
        $this->actingAs($admin)->put('/admin/settings/theme', [
            'accent_hue' => 230,
            'theme_mode' => 'dark',
        ])->assertNotFound();
    }
}
