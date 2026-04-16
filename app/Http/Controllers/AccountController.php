<?php

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

        return Inertia::render('Account/Settings', [
            'user' => [
                'id' => $user->id,
                'nickname' => $user->nickname,
                'email' => $user->email,
                'has_password' => $user->password !== null,
                'passkeys' => $user->webAuthnCredentials()->get()->map(fn ($cred) => [
                    'id' => $cred->id,
                    'name' => $cred->alias ?? 'Passkey',
                    'created_at' => $cred->created_at->toDateTimeString(),
                ]),
            ],
            'verified' => $request->session()->get('account_verified', false),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate(['password' => 'required|string']);

        if (! Hash::check($request->password, $request->user()->password)) {
            return back()->withErrors(['password' => 'Incorrect password.']);
        }

        $request->session()->put('account_verified', true);

        return back();
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => 'required|string|min:8|confirmed',
        ]);

        $request->user()->password = $request->password;
        $request->user()->save();

        return back()->with('success', 'Password updated.');
    }

    public function clearPassword(Request $request): RedirectResponse
    {
        $request->user()->password = null;
        $request->user()->save();

        $request->session()->forget('account_verified');

        return back()->with('success', 'Password removed.');
    }
}
