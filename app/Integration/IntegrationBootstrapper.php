<?php

declare(strict_types=1);

namespace App\Integration;

use App\Enums\Integration;
use Closure;
use Illuminate\Contracts\Foundation\Application;

interface IntegrationBootstrapper
{
    public function integration(): Integration;

    /**
     * @return array<string, Closure(Application): (object|null)>
     */
    public function providers(): array;
}
