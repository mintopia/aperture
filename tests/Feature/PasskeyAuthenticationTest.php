<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PasskeyAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    protected function createAdminUser(): User
    {
        $user = User::factory()->withPassword()->create();
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Admin';
        $role->save();
        $user->roles()->attach($role);

        return $user;
    }

    public function test_passkey_registration_options_endpoint_exists(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->postJson('/passkeys/register/options');

        $response->assertOk();
    }

    public function test_passkey_registration_requires_authentication(): void
    {
        $response = $this->postJson('/passkeys/register/options');

        $response->assertUnauthorized();
    }

    public function test_passkey_authentication_options_endpoint_exists(): void
    {
        $user = User::factory()->withPassword()->create([
            'email' => 'passkey@test.com',
        ]);

        $response = $this->postJson('/passkeys/login/options', [
            'email' => 'passkey@test.com',
        ]);

        $response->assertOk();
    }

    public function test_login_page_renders_with_passkey_support(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Auth/Login'));
    }
}
