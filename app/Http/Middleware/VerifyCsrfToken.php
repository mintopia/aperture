<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * @var array<int, string>
     */
    protected $except = [];
}
