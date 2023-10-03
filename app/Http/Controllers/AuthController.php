<?php

namespace App\Http\Controllers;

use App\Models\AuthProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Two\InvalidStateException;

class AuthController extends Controller
{
    public function login()
    {
        $providers = AuthProvider::whereEnabled(true)->get();
        return view('login', [
            'providers' => $providers,
        ]);
    }

    public function redirect(AuthProvider $provider)
    {
        return $provider->getBackend()->redirect();
    }

    public function handle(Request $request, AuthProvider $provider)
    {
        try {
            $user = $provider->getBackend()->user();
        } catch (InvalidStateException $ex) {
            return response()->redirectToRoute('login');
        }
        $user->addIp($request->getClientIp());
        Auth::login($user);
        return response()->redirectToRoute('home');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->regenerate(true);
        return response()->redirectToRoute('home');
    }
}
