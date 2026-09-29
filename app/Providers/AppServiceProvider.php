<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\BandwidthAnomalyDetected;
use App\Events\DhcpPoolThresholdReached;
use App\Events\IpMacObserved;
use App\Events\SwitchUnreachable;
use App\Http\Controllers\E2e\DeviceApprovalController;
use App\Integration\InstallGuard;
use App\Listeners\CascadeMacOwnershipOnLink;
use App\Listeners\RecordBandwidthAnomaly;
use App\Listeners\RecordDhcpPoolThreshold;
use App\Listeners\RecordSwitchUnreachable;
use App\Models\IpAddress;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserIpAddress;
use App\Observers\IpAddressObserver;
use App\Observers\UserIpAddressObserver;
use App\Observers\UserObserver;
use App\Services\Auth\BorealisDeviceFlowService;
use App\Services\Auth\E2e\FakeDeviceFlowService;
use App\Services\Interfaces\AuthProviderInterface;
use App\Services\NetworkRangeService;
use App\Services\ThemeService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            AuthProviderInterface::class,
            $this->app->environment('playwright') ? FakeDeviceFlowService::class : BorealisDeviceFlowService::class
        );
        $this->app->scoped(ThemeService::class);
        $this->app->scoped(NetworkRangeService::class);
    }

    public function boot(): void
    {
        if ($this->app->environment('playwright')) {
            config(['cache.default' => 'file', 'cache.stores.file.path' => storage_path('framework/cache/playwright')]);
            Route::middleware('api')->post('/api/e2e/device/approve', DeviceApprovalController::class);
        }

        if ($this->app->environment('production') && config('app.debug')) {
            Log::critical('APP_DEBUG is enabled in production. Disable it to prevent information disclosure.');
        }

        $trustedProxyIps = $_SERVER['TRUSTED_PROXY_IPS'] ?? $_ENV['TRUSTED_PROXY_IPS'] ?? '*';
        if ($trustedProxyIps === '*' && ! $this->app->environment('local', 'testing')) {
            Log::warning("TRUSTED_PROXY_IPS is set to '*' — all X-Forwarded-For headers are trusted. Configure specific proxy IPs for production.");
        }

        Model::preventLazyLoading(! $this->app->isProduction());

        User::observe(UserObserver::class);
        IpAddress::observe(IpAddressObserver::class);
        UserIpAddress::observe(UserIpAddressObserver::class);

        $this->registerEventListeners();
        $this->registerRateLimiters();
        $this->registerUrlPathFormatter();

        View::composer('*', static function (\Illuminate\Contracts\View\View $view): void {
            $view->with('siteTitle', (string) InstallGuard::tolerateMissingTable(
                static fn (): mixed => Setting::get('general.site_title', config('app.name', 'Aperture')),
                config('app.name', 'Aperture'),
            ));
        });
    }

    private function registerEventListeners(): void
    {
        Event::listen(IpMacObserved::class, CascadeMacOwnershipOnLink::class);
        Event::listen(SwitchUnreachable::class, RecordSwitchUnreachable::class);
        Event::listen(BandwidthAnomalyDetected::class, RecordBandwidthAnomaly::class);
        Event::listen(DhcpPoolThresholdReached::class, RecordDhcpPoolThreshold::class);
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->input('email', '');

            return Limit::perMinute(5)->by(mb_strtolower($email).'|'.$request->ip());
        });

        RateLimiter::for('captive-portal', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });
    }

    private function registerUrlPathFormatter(): void
    {
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
