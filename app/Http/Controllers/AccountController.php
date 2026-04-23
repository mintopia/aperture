<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(403); // Required for PHPStan level 8 null-safety
        }

        return Inertia::render('Account/Settings', [
            'user' => [
                'id' => $user->id,
                'nickname' => $user->nickname,
                'email' => $user->email,
                'has_password' => $user->password !== null,
                'passkeys' => $user->webAuthnCredentials()->get()->map(fn ($cred): array => [
                    'id' => $cred->id,
                    'name' => $cred->alias ?? 'Passkey',
                    'created_at' => $cred->created_at->toIso8601String(),
                ]),
            ],
            'verified' => $request->session()->get('account_verified', $user->password === null),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(403); // Required for PHPStan level 8 null-safety
        }

        $request->validate(['password' => 'required|string']);

        if ($user->password === null || ! Hash::check($request->string('password')->value(), $user->password)) {
            return back()->withErrors(['password' => 'Incorrect password.']);
        }

        $request->session()->put('account_verified', true);

        return back();
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(403); // Required for PHPStan level 8 null-safety
        }

        $request->validate([
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user->password = $request->password;
        $user->save();

        return back()->with('success', 'Password updated.');
    }

    public function clearPassword(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(403); // Required for PHPStan level 8 null-safety
        }

        $user->password = null;
        $user->save();

        $request->session()->forget('account_verified');

        return back()->with('success', 'Password removed.');
    }
}
