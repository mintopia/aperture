<?php

namespace App\Providers;

use App\Services\Auth\BorealisDeviceFlowService;
use App\Services\BorealisService;
use App\Services\Interfaces\AuthProviderInterface;
use App\Services\LibreNmsService;
use App\Services\NtopNgService;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AuthProviderInterface::class, BorealisDeviceFlowService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->singleton(function (Application $application): NtopNgService {
            return new NtopNgService(
                endpoint: config('aperture.ntopng.endpoint'),
                username: config('aperture.ntopng.username'),
                password: config('aperture.ntopng.password'),
                interface: config('aperture.ntopng.interface'),
            );
        });

        $this->app->singleton(function (Application $application): BorealisService {
            return new BorealisService(
                clientId: config('aperture.borealis.client_id'),
                clientSecret: config('aperture.borealis.client_secret'),
                endpoint: config('aperture.borealis.endpoint'),
            );
        });

        $this->app->singleton(function (Application $application): LibreNmsService {
            return new LibreNmsService(
                endpoint: config('aperture.librenms.endpoint', ''),
                apiToken: config('aperture.librenms.api_token', ''),
            );
        });
    }
}
