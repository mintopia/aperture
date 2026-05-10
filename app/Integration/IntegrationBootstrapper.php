<?php

declare(strict_types=1);

namespace App\Integration;

use Illuminate\Contracts\Foundation\Application;

interface IntegrationBootstrapper
{
    public function register(Application $app): void;
}
