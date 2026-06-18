<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountSecurityVerified
{
    public function handle(Request $request, Closure $next): Response|JsonResponse|RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Authentication required.'], 401);
        }

        $hasSecurityMethod = $user->password !== null || $user->webAuthnCredentials()->exists();

        if (! $hasSecurityMethod) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Please create a password to manage passkeys.'], 403);
            }

            return redirect()
                ->route('account.settings')
                ->with('error', 'Please create a password to manage passkeys.');
        }

        $isVerified = (bool) $request->session()->get('account_verified', false);

        if (! $isVerified) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Please verify your account before managing passkeys.'], 403);
            }

            return redirect()
                ->route('account.settings')
                ->with('error', 'Please verify your account before managing passkeys.');
        }

        return $next($request);
    }
}
