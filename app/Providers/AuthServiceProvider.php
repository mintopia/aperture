<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        Gate::define('viewWebSocketsDashboard', function (User $user): bool {
            return $user->hasRole('admin');
        });
        Gate::define('admin', function (User $user): bool {
            return $user->hasRole('admin');
        });
    }
}
