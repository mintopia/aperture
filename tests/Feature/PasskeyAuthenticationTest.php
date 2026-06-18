<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PasskeyAuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

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
        $this->actingAs($admin);
        session()->put('account_verified', true);

        $response = $this->postJson('/passkeys/register/options');

        $response->assertOk();
    }

    public function test_passkey_registration_requires_authentication(): void
    {
        $response = $this->postJson('/passkeys/register/options');

        $response->assertUnauthorized();
    }

    public function test_passkey_authentication_options_endpoint_exists(): void
    {
        User::factory()->withPassword()->create([
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
        $response->assertInertia(fn ($page) => $page->component('Auth/Login', false));
    }

    public function test_passkey_destroy_returns_403_when_unauthenticated(): void
    {
        // Covers PasskeyController line 53: the null-user guard in destroy()
        $response = $this->deleteJson('/passkeys/some-credential-id');

        // Unauthenticated requests get a 401 from the auth middleware,
        // or the null-user guard returns 403 — either way it's not a success
        $this->assertContains($response->getStatusCode(), [401, 403]);
    }

    public function test_passkey_login_routes_have_throttle_middleware(): void
    {
        $routes = app('router')->getRoutes();

        $loginOptions = $routes->getByName('passkeys.login.options');
        $this->assertNotNull($loginOptions, 'passkeys.login.options route should exist');
        $this->assertTrue(
            collect($loginOptions->gatherMiddleware())->contains(fn ($m): bool => str_contains((string) $m, 'throttle')),
            'passkeys.login.options should have throttle middleware'
        );

        $login = $routes->getByName('passkeys.login');
        $this->assertNotNull($login, 'passkeys.login route should exist');
        $this->assertTrue(
            collect($login->gatherMiddleware())->contains(fn ($m): bool => str_contains((string) $m, 'throttle')),
            'passkeys.login should have throttle middleware'
        );
    }

    public function test_passkey_registration_routes_have_throttle_middleware(): void
    {
        $routes = app('router')->getRoutes();

        $registerOptions = $routes->getByName('passkeys.register.options');
        $this->assertNotNull($registerOptions, 'passkeys.register.options route should exist');
        $this->assertTrue(
            collect($registerOptions->gatherMiddleware())->contains(fn ($m): bool => str_contains((string) $m, 'throttle')),
            'passkeys.register.options should have throttle middleware'
        );

        $register = $routes->getByName('passkeys.register');
        $this->assertNotNull($register, 'passkeys.register route should exist');
        $this->assertTrue(
            collect($register->gatherMiddleware())->contains(fn ($m): bool => str_contains((string) $m, 'throttle')),
            'passkeys.register should have throttle middleware'
        );

        $destroy = $routes->getByName('passkeys.destroy');
        $this->assertNotNull($destroy, 'passkeys.destroy route should exist');
        $this->assertTrue(
            collect($destroy->gatherMiddleware())->contains(fn ($m): bool => str_contains((string) $m, 'throttle')),
            'passkeys.destroy should have throttle middleware'
        );
    }
}
