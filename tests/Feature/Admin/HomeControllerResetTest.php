<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Jobs\ResetAperture;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HomeControllerResetTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function createAdminUser(?string $password = 'password'): User
    {
        $factory = User::factory();
        if ($password !== null) {
            $factory = $factory->withPassword($password);
        }
        $user = $factory->create();
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Admin';
        $role->save();
        $user->roles()->attach($role);

        return $user;
    }

    public function test_reset_requires_password(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/reset');

        $response->assertSessionHasErrors('password');
        Queue::assertNothingPushed();
    }

    public function test_reset_rejects_wrong_password(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser('correct-password');

        $response = $this->actingAs($admin)->post('/admin/reset', [
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('password');
        Queue::assertNothingPushed();
    }

    public function test_reset_rejects_user_without_password(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser(null);

        $response = $this->actingAs($admin)->post('/admin/reset', [
            'password' => 'any-password',
        ]);

        $response->assertSessionHasErrors('password');
        Queue::assertNothingPushed();
    }

    public function test_reset_succeeds_with_correct_password(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser('correct-password');

        $response = $this->actingAs($admin)->post('/admin/reset', [
            'password' => 'correct-password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        Queue::assertPushed(ResetAperture::class);
    }

    public function test_reset_creates_audit_log_entry(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser('correct-password');

        $this->actingAs($admin)->post('/admin/reset', [
            'password' => 'correct-password',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'portal.reset',
            'subject_type' => $admin->getMorphClass(),
            'subject_id' => $admin->id,
            'actor_type' => $admin->getMorphClass(),
            'actor_id' => $admin->id,
            'process' => 'admin',
        ]);
    }

    public function test_reset_audit_log_contains_ip_address(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser('correct-password');

        $this->actingAs($admin)->post('/admin/reset', [
            'password' => 'correct-password',
        ]);

        $log = AuditLog::where('action', 'portal.reset')->first();
        $this->assertNotNull($log);
        $this->assertIsArray($log->metadata);
        $this->assertArrayHasKey('ip', $log->metadata);
    }

    public function test_non_admin_cannot_trigger_reset(): void
    {
        Queue::fake();
        $user = User::factory()->withPassword('password')->create();

        $response = $this->actingAs($user)->post('/admin/reset', [
            'password' => 'password',
        ]);

        $response->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_unauthenticated_user_cannot_trigger_reset(): void
    {
        $response = $this->post('/admin/reset');

        $response->assertRedirect('/captive');
    }
}
