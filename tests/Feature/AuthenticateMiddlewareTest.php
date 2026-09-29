<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\Authenticate;
use Illuminate\Http\Request;
use ReflectionClass;
use Tests\TestCase;

class AuthenticateMiddlewareTest extends TestCase
{
    public function test_redirect_to_returns_captive_route_for_non_json_requests(): void
    {
        $middleware = new Authenticate($this->app->make('auth'));

        $reflection = new ReflectionClass($middleware);
        $method = $reflection->getMethod('redirectTo');

        $request = Request::create('/test');
        $result = $method->invoke($middleware, $request);

        $this->assertEquals(route('captive.index'), $result);
    }

    public function test_redirect_to_returns_null_for_json_requests(): void
    {
        $middleware = new Authenticate($this->app->make('auth'));

        $reflection = new ReflectionClass($middleware);
        $method = $reflection->getMethod('redirectTo');

        $request = Request::create('/test');
        $request->headers->set('Accept', 'application/json');

        $result = $method->invoke($middleware, $request);
        $this->assertNull($result);
    }

    public function test_inertia_request_returns_409_with_login_location(): void
    {
        $response = $this->get('/admin', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => '1',
        ]);

        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location', route('login'));
    }

    public function test_non_inertia_request_redirects_to_captive(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirect(route('captive.index'));
    }
}
