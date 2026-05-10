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

            $defaultUrl = $user->hasRole('admin') ? route('admin.home') : '/';

            return redirect()->intended($defaultUrl);
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
