<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        $this->routes(function (): void {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });

        // Ensure route parameters with slashes (e.g. portId "Gi0/1") are
        // URL-encoded when generating URLs, so route() produces %2F instead
        // of a bare slash which would create extra path segments.
        URL::formatPathUsing(function (string $path, ?RoutingRoute $route): string {
            if (! $route instanceof RoutingRoute || ! str_contains($route->uri(), '{portId}')) {
                return $path;
            }

            $uriSegments = explode('/', trim($route->uri(), '/'));
            $pathSegments = explode('/', trim($path, '/'));

            $portIdIndex = array_search('{portId}', $uriSegments, true);
            if ($portIdIndex === false) {
                return $path;
            }

            $extraSegments = count($pathSegments) - count($uriSegments);
            if ($extraSegments <= 0) {
                return $path;
            }

            $portIdParts = array_slice($pathSegments, (int) $portIdIndex, $extraSegments + 1);
            $encodedPortId = rawurlencode(implode('/', $portIdParts));

            $newSegments = array_merge(
                array_slice($pathSegments, 0, (int) $portIdIndex),
                [$encodedPortId],
                array_slice($pathSegments, (int) $portIdIndex + $extraSegments + 1),
            );

            return '/'.implode('/', $newSegments);
        });
    }
}
