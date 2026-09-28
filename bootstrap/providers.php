<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\BroadcastServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\IntegrationServiceProvider;
use App\Providers\NetworkServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    BroadcastServiceProvider::class,
    HorizonServiceProvider::class,
    IntegrationServiceProvider::class,
    NetworkServiceProvider::class,
];
