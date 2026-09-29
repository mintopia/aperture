<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laragear\WebAuthn\Http\Requests\AssertedRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;
use Mockery;
use Tests\Feature\Concerns\CreatesAdminUsers;
use Tests\TestCase;

class PasskeyAuthenticationTest extends TestCase
{
    use CreatesAdminUsers;
    use LazilyRefreshDatabase;

    public function test_passkey_registration_options_endpoint_exists(): void
    {
        $admin = $this->createAdminUser(withPassword: true);
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
        $response = $this->deleteJson('/passkeys/some-credential-id');

        $this->assertContains($response->getStatusCode(), [401, 403]);
    }

    public function test_passkey_login_routes_have_throttle_middleware(): void
    {
        $routes = resolve('router')->getRoutes();

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
        $routes = resolve('router')->getRoutes();

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

    public function test_passkey_registration_saves_credential_and_audits(): void
    {
        $user = User::factory()->withPassword()->create();
        $attested = Mockery::mock(AttestedRequest::class)->makePartial();
        $attested->shouldReceive('save')->once()->andReturn('credential-id-123');
        $attested->shouldReceive('user')->andReturn($user);
        $attested->shouldReceive('getClientIp')->andReturn('127.0.0.1');
        $this->app->instance(AttestedRequest::class, $attested);

        $response = $this->actingAs($user)->withSession(['account_verified' => true])->postJson('/passkeys/register');

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.passkey_registered']);
    }

    public function test_passkey_login_succeeds_for_valid_assertion(): void
    {
        $user = User::factory()->create();
        $asserted = Mockery::mock(AssertedRequest::class)->makePartial();
        $asserted->shouldReceive('login')->once()->andReturn($user);
        $asserted->shouldReceive('session')->andReturn($this->app['session.store']);
        $this->app->instance(AssertedRequest::class, $asserted);

        $response = $this->postJson('/passkeys/login');

        $response->assertOk()->assertJson(['success' => true, 'redirect' => '/']);
    }

    public function test_passkey_login_returns_422_when_assertion_fails(): void
    {
        $asserted = Mockery::mock(AssertedRequest::class)->makePartial();
        $asserted->shouldReceive('login')->once()->andReturn(null);
        $this->app->instance(AssertedRequest::class, $asserted);

        $response = $this->postJson('/passkeys/login');

        $response->assertStatus(422)->assertJson(['success' => false, 'message' => 'Authentication failed.']);
    }
}
