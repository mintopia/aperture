<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Controllers\E2e\DeviceApprovalController;
use App\Integration\InstallGuard;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            AuthProviderInterface::class,
            $this->app->environment('playwright') ? FakeDeviceFlowService::class : BorealisDeviceFlowService::class
        );
        $this->app->scoped(ThemeService::class);
        $this->app->scoped(NetworkRangeService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('playwright')) {
            // php -S handles each request in a fresh process, so the device flow needs a shared cache.
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

        View::composer('*', static function (\Illuminate\Contracts\View\View $view): void {
            $view->with('siteTitle', (string) InstallGuard::tolerateMissingTable(
                static fn (): mixed => Setting::get('general.site_title', config('app.name', 'Aperture')),
                config('app.name', 'Aperture'),
            ));
        });
    }
}
