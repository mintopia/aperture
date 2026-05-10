<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LoginControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected bool $seedSetupUser = false;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_login_page_renders(): void
    {
        User::factory()->create();

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Auth/Login'));
    }

    public function test_authenticated_user_is_redirected_from_login(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get('/login');

        $response->assertRedirect('/');
    }

    public function test_authenticated_admin_is_redirected_to_admin_dashboard_from_login(): void
    {
        $adminRole = new Role;
        $adminRole->code = 'admin';
        $adminRole->name = 'Admin';
        $adminRole->save();

        $user = User::factory()->create();
        $user->roles()->attach($adminRole);

        $response = $this->actingAs($user)->get('/login');

        $response->assertRedirect(route('admin.home'));
    }

    public function test_user_can_login_with_email_and_password(): void
    {
        $user = User::factory()->withPassword('secret123')->create([
            'email' => 'admin@test.com',
        ]);

        $response = $this->post('/login', [
            'email' => 'admin@test.com',
            'password' => 'secret123',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_authenticate_does_not_create_user_when_none_exist(): void
    {
        $response = $this->post('/login', [
            'email' => 'first-admin@test.com',
            'password' => 'secret123',
        ]);

        // With no users, the middleware redirects to /setup, so login never fires
        $response->assertRedirect('/setup');
        $this->assertDatabaseMissing('users', [
            'email' => 'first-admin@test.com',
        ]);
        $this->assertGuest();
    }

    public function test_admin_user_is_redirected_to_admin_dashboard_after_login(): void
    {
        $adminRole = new Role;
        $adminRole->code = 'admin';
        $adminRole->name = 'Admin';
        $adminRole->save();

        $user = User::factory()->withPassword('secret123')->create([
            'email' => 'admin@test.com',
        ]);
        $user->roles()->attach($adminRole);

        $response = $this->post('/login', [
            'email' => 'admin@test.com',
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('admin.home'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->withPassword('secret123')->create([
            'email' => 'admin@test.com',
        ]);

        $response = $this->post('/login', [
            'email' => 'admin@test.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_fails_for_user_without_password(): void
    {
        User::factory()->create([
            'email' => 'user@test.com',
            'password' => null,
        ]);

        $response = $this->post('/login', [
            'email' => 'user@test.com',
            'password' => 'anything',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_fails_for_nonexistent_email(): void
    {
        User::factory()->withPassword('secret123')->create([
            'email' => 'existing@test.com',
        ]);

        $response = $this->post('/login', [
            'email' => 'nobody@test.com',
            'password' => 'anything',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_validates_required_fields(): void
    {
        User::factory()->create();

        $response = $this->post('/login', []);

        $response->assertSessionHasErrors(['email', 'password']);
    }

    public function test_login_post_route_has_throttle_middleware(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutesByMethod()['POST'])
            ->first(fn ($r): bool => $r->uri() === 'login');

        $this->assertNotNull($route, 'POST /login route should exist');
        $this->assertTrue(
            collect($route->gatherMiddleware())->contains(fn ($m): bool => str_contains((string) $m, 'throttle')),
            'POST /login should have throttle middleware'
        );
    }

    public function test_login_is_rate_limited_by_throttle_middleware(): void
    {
        User::factory()->withPassword('secret')->create([
            'email' => 'admin@test.com',
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', [
                'email' => 'admin@test.com',
                'password' => 'wrong',
            ]);
        }

        $response = $this->post('/login', [
            'email' => 'admin@test.com',
            'password' => 'wrong',
        ]);

        $response->assertStatus(429);
    }

    public function test_successful_login_is_not_blocked_by_rate_limit(): void
    {
        User::factory()->withPassword('secret123')->create([
            'email' => 'admin@test.com',
        ]);

        // Make 3 failed attempts (under limit)
        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', [
                'email' => 'admin@test.com',
                'password' => 'wrong',
            ]);
        }

        $response = $this->post('/login', [
            'email' => 'admin@test.com',
            'password' => 'secret123',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_authenticate_middleware_still_redirects_to_captive(): void
    {
        User::factory()->create();

        $response = $this->get('/');
        $response->assertRedirect(route('captive.index'));
    }

    public function test_successful_login_creates_audit_log(): void
    {
        User::factory()->withPassword('secret123')->create([
            'email' => 'audit@test.com',
        ]);

        $this->post('/login', [
            'email' => 'audit@test.com',
            'password' => 'secret123',
        ]);

        $this->assertTrue(AuditLog::where('action', 'user.login')->exists());
        $log = AuditLog::where('action', 'user.login')->first();
        $this->assertNotNull($log);
        $this->assertEquals('auth', $log->process);
        $this->assertEquals('audit@test.com', $log->metadata['email']);
        $this->assertArrayHasKey('ip', $log->metadata);
    }

    public function test_failed_login_creates_audit_log(): void
    {
        User::factory()->withPassword('secret123')->create([
            'email' => 'audit@test.com',
        ]);

        $this->post('/login', [
            'email' => 'audit@test.com',
            'password' => 'wrongpassword',
        ]);

        $this->assertTrue(AuditLog::where('action', 'user.login_failed')->exists());
        $log = AuditLog::where('action', 'user.login_failed')->first();
        $this->assertNotNull($log);
        $this->assertEquals('auth', $log->process);
        $this->assertNull($log->subject_type);
        $this->assertNull($log->subject_id);
        $this->assertEquals('audit@test.com', $log->metadata['email']);
        $this->assertArrayHasKey('ip', $log->metadata);
    }
}
