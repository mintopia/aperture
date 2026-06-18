<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function showLoginForm(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function authenticate(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials)) {
            $request->session()->regenerate();

            /** @var User $user */
            $user = Auth::user();

            AuditLog::record(
                action: 'user.login',
                subject: $user,
                process: 'auth',
                metadata: ['email' => $user->email, 'ip' => $request->getClientIp()],
            );

            if ($user->hasRole('admin')) {
                $intended = $request->session()->pull('url.intended');
                $intendedPath = is_string($intended) ? parse_url($intended, PHP_URL_PATH) : null;

                if (is_string($intended) && is_string($intendedPath) && ($intendedPath === '/admin' || str_starts_with($intendedPath, '/admin/'))) {
                    return redirect($intended);
                }

                return redirect()->route('admin.home');
            }

            return redirect()->intended('/');
        }

        AuditLog::record(
            action: 'user.login_failed',
            process: 'auth',
            metadata: ['email' => $credentials['email'], 'ip' => $request->getClientIp()],
        );

        throw ValidationException::withMessages([
            'email' => __('The provided credentials do not match our records.'),
        ]);
    }
}
