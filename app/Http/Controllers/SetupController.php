<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SetupController extends Controller
{
    public function index(): Response|RedirectResponse
    {
        if (User::query()->exists()) {
            return redirect()->route('login');
        }

        return Inertia::render('Setup/Index');
    }

    public function store(Request $request): RedirectResponse
    {
        if ($this->usersExist()) {
            return redirect()->route('login');
        }

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $lock = Cache::lock('aperture_setup', 10);

        if (! $lock->get()) {
            return redirect()->route('login');
        }

        try {
            if ($this->usersExist()) {
                return redirect()->route('login');
            }

            /** @var User $user */
            $user = DB::transaction(function () use ($validated): User {
                $adminRole = Role::query()->firstOrCreate(
                    ['code' => 'admin'],
                    ['name' => 'Admin']
                );

                $userRole = Role::query()->firstOrCreate(
                    ['code' => 'user'],
                    ['name' => 'User']
                );

                $nickname = Str::before($validated['email'], '@');
                if ($nickname === '') {
                    $nickname = 'admin';
                }

                $user = new User;
                $user->email = $validated['email'];
                $user->nickname = $nickname;
                $user->password = $validated['password'];
                $user->save();

                $user->roles()->syncWithoutDetaching([$adminRole->id, $userRole->id]);

                return $user;
            });

            Auth::login($user);
            $request->session()->regenerate();

            return redirect()->route('admin.home');
        } finally {
            $lock->release();
        }
    }

    /**
     * @phpstan-impure
     */
    private function usersExist(): bool
    {
        return User::query()->exists();
    }
}
