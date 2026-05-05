<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        return $request->expectsJson() ? null : route('captive.index');
    }

    /**
     * Handle an unauthenticated user.
     *
     * For Inertia requests, force a full page visit to the login route
     * so the login page renders with a clean layout instead of being
     * embedded inside the previous page's layout.
     *
     * @param  array<int, string>  $guards
     */
    protected function unauthenticated($request, array $guards): never
    {
        if ($request->inertia()) {
            abort(Response::HTTP_CONFLICT, '', ['X-Inertia-Location' => route('login')]);
        }

        parent::unauthenticated($request, $guards);
    }
}
