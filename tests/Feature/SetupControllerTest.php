<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SetupControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected bool $seedSetupUser = false;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_setup_page_accessible_when_no_users_exist(): void
    {
        $response = $this->get('/setup');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Setup/Index'));
    }

    public function test_setup_page_redirects_to_login_when_users_exist(): void
    {
        User::factory()->create();

        $response = $this->get('/setup');

        $response->assertRedirect(route('login'));
    }

    public function test_setup_creates_admin_user(): void
    {
        $this->post('/setup', [
            'email' => 'admin@test.com',
            'password' => 'supersecret12',
            'password_confirmation' => 'supersecret12',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'admin@test.com',
        ]);

        $user = User::query()->where('email', 'admin@test.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('supersecret12', $user->password));
    }

    public function test_setup_validates_email_required(): void
    {
        $response = $this->post('/setup', [
            'password' => 'supersecret12',
            'password_confirmation' => 'supersecret12',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_setup_validates_password_min_length(): void
    {
        $response = $this->post('/setup', [
            'email' => 'admin@test.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_setup_validates_password_confirmation(): void
    {
        $response = $this->post('/setup', [
            'email' => 'admin@test.com',
            'password' => 'supersecret12',
            'password_confirmation' => 'different1234',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_setup_redirects_after_completion(): void
    {
        $response = $this->post('/setup', [
            'email' => 'admin@test.com',
            'password' => 'supersecret12',
            'password_confirmation' => 'supersecret12',
        ]);

        $response->assertRedirect(route('admin.home'));
    }

    public function test_setup_prevents_creation_when_users_already_exist(): void
    {
        User::factory()->create();

        $response = $this->post('/setup', [
            'email' => 'admin@test.com',
            'password' => 'supersecret12',
            'password_confirmation' => 'supersecret12',
        ]);

        $response->assertRedirect(route('login'));
        $this->assertDatabaseMissing('users', [
            'email' => 'admin@test.com',
        ]);
    }

    public function test_setup_assigns_admin_and_user_roles(): void
    {
        $this->post('/setup', [
            'email' => 'admin@test.com',
            'password' => 'supersecret12',
            'password_confirmation' => 'supersecret12',
        ]);

        $user = User::query()->where('email', 'admin@test.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->hasRole('user'));
    }

    public function test_setup_logs_user_in_after_creation(): void
    {
        $this->post('/setup', [
            'email' => 'admin@test.com',
            'password' => 'supersecret12',
            'password_confirmation' => 'supersecret12',
        ]);

        $this->assertAuthenticated();

        $user = User::query()->where('email', 'admin@test.com')->first();
        $this->assertNotNull($user);
        $this->assertAuthenticatedAs($user);
    }
}
