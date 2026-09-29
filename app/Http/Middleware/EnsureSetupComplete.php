<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureSetupComplete
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isSetupRoute($request)) {
            return $next($request);
        }

        if ($this->setupRequired()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Application setup is not complete.',
                ], 503);
            }

            return redirect('/setup');
        }

        return $next($request);
    }

    private function isSetupRoute(Request $request): bool
    {
        return $request->is('setup') || $request->is('setup/*');
    }

    private function setupRequired(): bool
    {
        try {
            return User::query()->doesntExist();
        } catch (Throwable) {
            return false;
        }
    }
}
