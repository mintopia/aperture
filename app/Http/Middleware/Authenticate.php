<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Inertia\Inertia;

class Authenticate extends Middleware
{
    protected function redirectTo(Request $request): ?string
    {
        return $request->expectsJson() ? null : route('captive.index');
    }

    /**
     * @param  array<int, string>  $guards
     */
    protected function unauthenticated($request, array $guards): never
    {
        if ($request->inertia()) {
            throw new HttpResponseException(Inertia::location(route('login')));
        }

        parent::unauthenticated($request, $guards);
    }
}
