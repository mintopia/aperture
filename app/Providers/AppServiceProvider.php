<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\IpAddress;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserIpAddress;
use App\Observers\IpAddressObserver;
use App\Observers\UserIpAddressObserver;
use App\Observers\UserObserver;
use App\Services\Auth\BorealisDeviceFlowService;
use App\Services\Interfaces\AuthProviderInterface;
use App\Services\NetworkRangeService;
use App\Services\ThemeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AuthProviderInterface::class, BorealisDeviceFlowService::class);
        $this->app->scoped(ThemeService::class);
        $this->app->scoped(NetworkRangeService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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

        try {
            $siteTitle = (string) Setting::get('general.site_title', config('app.name', 'Aperture'));
        } catch (Throwable) {
            $siteTitle = (string) config('app.name', 'Aperture');
        }

        View::share('siteTitle', $siteTitle);
    }
}
