<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\IntegrationConfig;
use App\Models\IpAddress;
use App\Models\SwitchConfig;
use App\Models\User;
use App\Policies\IntegrationConfigPolicy;
use App\Policies\IpAddressPolicy;
use App\Policies\SwitchConfigPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        User::class => UserPolicy::class,
        IpAddress::class => IpAddressPolicy::class,
        SwitchConfig::class => SwitchConfigPolicy::class,
        IntegrationConfig::class => IntegrationConfigPolicy::class,
    ];

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
