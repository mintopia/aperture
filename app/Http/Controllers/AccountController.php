<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AccountCreatePasswordRequest;
use App\Http\Requests\AccountUpdatePasswordRequest;
use App\Http\Requests\AccountVerifyRequest;
use App\Models\AuditLog;
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
            abort(403);
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
            'verified' => (bool) $request->session()->get('account_verified', false),
        ]);
    }

    public function verify(AccountVerifyRequest $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        if ($user->password === null || ! Hash::check($request->string('password')->value(), $user->password)) {
            return back()->withErrors(['password' => 'Incorrect password.']);
        }

        $request->session()->put('account_verified', true);

        return back();
    }

    public function createPassword(AccountCreatePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        if ($user->password !== null) {
            abort(403);
        }

        $user->update(['password' => Hash::make($request->string('password')->value())]);

        AuditLog::record(
            action: 'user.password_created',
            subject: $user,
            process: 'account',
            metadata: ['ip' => $request->getClientIp()],
        );

        $request->session()->put('account_verified', true);

        return back()->with('success', 'Password created.');
    }

    public function updatePassword(AccountUpdatePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $user->password = $request->password;
        $user->save();

        AuditLog::record(
            action: 'user.password_changed',
            subject: $user,
            process: 'account',
            metadata: ['ip' => $request->getClientIp()],
        );

        return back()->with('success', 'Password updated.');
    }

    public function clearPassword(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $user->password = null;
        $user->save();

        AuditLog::record(
            action: 'user.password_cleared',
            subject: $user,
            process: 'account',
            metadata: ['ip' => $request->getClientIp()],
        );

        $request->session()->forget('account_verified');

        return back()->with('success', 'Password removed.');
    }
}
