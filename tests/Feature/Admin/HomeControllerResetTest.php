<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Jobs\ResetAperture;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HomeControllerResetTest extends TestCase
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

    public function test_admin_can_trigger_reset(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/reset');

        $response->assertRedirect();
        Queue::assertPushed(ResetAperture::class);
    }

    public function test_non_admin_cannot_trigger_reset(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/admin/reset');

        $response->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_unauthenticated_user_cannot_trigger_reset(): void
    {
        $response = $this->post('/admin/reset');

        $response->assertRedirect('/captive');
    }
}
