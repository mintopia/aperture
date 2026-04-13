<?php

namespace Tests\Unit\Providers;

use App\Providers\RouteServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class RouteServiceProviderTest extends TestCase
{
    public function test_home_constant_is_defined(): void
    {
        $this->assertEquals('/', RouteServiceProvider::HOME);
    }

    public function test_api_rate_limiter_is_configured(): void
    {
        $this->assertTrue(RateLimiter::limiter('api') !== null);
    }

    public function test_api_rate_limiter_returns_limit_for_guest(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->setUserResolver(fn (): null => null);

        $limiter = RateLimiter::limiter('api');
        $limits = $limiter($request);
        $this->assertInstanceOf(Limit::class, $limits);
    }
}
