<?php

namespace Tests\Unit\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AppServiceProviderRoutingTest extends TestCase
{
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

    public function test_format_path_returns_path_unchanged_for_non_portid_route(): void
    {
        $urlGenerator = $this->app->make(UrlGenerator::class);
        $formatter = $urlGenerator->pathFormatter();

        $route = new RoutingRoute(['GET'], 'admin/switches/{id}/ports', fn (): string => '');
        $path = '/admin/switches/1/ports';

        $result = $formatter($path, $route);
        $this->assertSame($path, $result);
    }

    public function test_format_path_returns_unchanged_when_portid_embedded_in_segment(): void
    {
        $urlGenerator = $this->app->make(UrlGenerator::class);
        $formatter = $urlGenerator->pathFormatter();

        $route = new RoutingRoute(['GET'], 'admin/prefix{portId}suffix/show', fn (): string => '');

        $path = '/admin/prefixGi0/1suffix/show';
        $result = $formatter($path, $route);

        $this->assertSame($path, $result);
    }
}
