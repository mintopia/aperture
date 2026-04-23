<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->regenerate(true);

        return response()->redirectToRoute('home');
    }
}
