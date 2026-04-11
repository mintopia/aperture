<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class HandleInertiaRequestsMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_shares_auth_user_data(): void
    {
        $user = User::factory()->create();

        $middleware = new HandleInertiaRequests;
        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession(session()->driver());

        $shared = $middleware->share($request);

        $this->assertArrayHasKey('auth', $shared);
        $this->assertArrayHasKey('user', $shared['auth']);
        $this->assertEquals($user->id, $shared['auth']['user']->id);
    }

    public function test_shares_flash_messages(): void
    {
        $user = User::factory()->create();

        $middleware = new HandleInertiaRequests;
        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);

        $session = session()->driver();
        $session->flash('success', 'Test message');

        $request->setLaravelSession($session);

        $shared = $middleware->share($request);

        $this->assertArrayHasKey('flash', $shared);
        $this->assertArrayHasKey('success', $shared['flash']);
    }

    public function test_shares_theme_data(): void
    {
        $setting = Setting::whereCode('theme.name')->first() ?? new Setting;
        $setting->code = 'theme.name';
        $setting->name = 'Theme Name';
        $setting->value = 'warm-neon';
        $setting->save();

        $setting = Setting::whereCode('theme.mode')->first() ?? new Setting;
        $setting->code = 'theme.mode';
        $setting->name = 'Theme Mode';
        $setting->value = 'dark';
        $setting->save();

        $user = User::factory()->create();

        $middleware = new HandleInertiaRequests;
        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession(session()->driver());

        $shared = $middleware->share($request);

        $this->assertArrayHasKey('theme', $shared);
        $this->assertArrayHasKey('name', $shared['theme']);
        $this->assertArrayHasKey('mode', $shared['theme']);
    }
}
