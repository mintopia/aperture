<?php

namespace App\Providers;

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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->singleton(NtopNgService::class, function (Application $application) {
            return new NtopNgService(
                endpoint: config('aperture.ntopng.endpoint'),
                username: config('aperture.ntopng.username'),
                password: config('aperture.ntopng.password'),
                interface: config('aperture.ntopng.interface'),
            );
        });
    }
}
