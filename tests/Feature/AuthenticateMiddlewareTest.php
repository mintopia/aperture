<?php

namespace Tests\Feature;

use App\Http\Middleware\Authenticate;
use Illuminate\Http\Request;
use ReflectionClass;
use Tests\TestCase;

class AuthenticateMiddlewareTest extends TestCase
{
    public function test_redirect_to_returns_login_route_for_non_json_requests(): void
    {
        $middleware = new Authenticate($this->app->make('auth'));

        $reflection = new ReflectionClass($middleware);
        $method = $reflection->getMethod('redirectTo');

        $request = Request::create('/test');
        $result = $method->invoke($middleware, $request);

        $this->assertEquals(route('login'), $result);
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
}
