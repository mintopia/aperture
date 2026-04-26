<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
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

        if (User::query()->doesntExist()) {
            $adminRole = Role::query()->where('code', 'admin')->first() ?? new Role;
            $adminRole->code = 'admin';
            $adminRole->name = 'Admin';
            $adminRole->save();

            $userRole = Role::query()->where('code', 'user')->first() ?? new Role;
            $userRole->code = 'user';
            $userRole->name = 'User';
            $userRole->save();

            $nickname = Str::before($credentials['email'], '@');
            if ($nickname === '') {
                $nickname = 'admin';
            }

            $user = new User;
            $user->email = $credentials['email'];
            $user->nickname = $nickname;
            $user->password = $credentials['password'];
            $user->save();
            $user->roles()->syncWithoutDetaching([$adminRole->id, $userRole->id]);

            Auth::login($user);
            $request->session()->regenerate();

            return redirect()->intended(route('admin.home'));
        }

        if (Auth::attempt($credentials)) {
            $request->session()->regenerate();

            /** @var User $user */
            $user = Auth::user();
            $defaultUrl = $user->hasRole('admin') ? route('admin.home') : '/';

            return redirect()->intended($defaultUrl);
        }

        throw ValidationException::withMessages([
            'email' => __('The provided credentials do not match our records.'),
        ]);
    }
}
