<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LoginControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_login_page_renders(): void
    {
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

    public function test_first_login_bootstraps_admin_user_when_no_users_exist(): void
    {
        $adminRole = new Role;
        $adminRole->code = 'admin';
        $adminRole->name = 'Admin';
        $adminRole->save();

        $userRole = new Role;
        $userRole->code = 'user';
        $userRole->name = 'User';
        $userRole->save();

        $response = $this->post('/login', [
            'email' => 'first-admin@test.com',
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('admin.home'));

        $createdUser = User::query()->where('email', 'first-admin@test.com')->first();

        $this->assertNotNull($createdUser);
        $this->assertTrue($createdUser->hasRole('admin'));
        $this->assertTrue($createdUser->hasRole('user'));
        $this->assertAuthenticatedAs($createdUser);
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
        $response = $this->post('/login', []);

        $response->assertSessionHasErrors(['email', 'password']);
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->withPassword('secret')->create([
            'email' => 'admin@test.com',
        ]);

        for ($i = 0; $i < 6; $i++) {
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

    public function test_authenticate_middleware_still_redirects_to_captive(): void
    {
        $response = $this->get('/');
        $response->assertRedirect(route('captive.index'));
    }
}
