<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function logout(Request $request): RedirectResponse
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user) {
            AuditLog::record(
                action: 'user.logout',
                subject: $user,
                process: 'auth',
                metadata: ['ip' => $request->getClientIp()],
            );
        }

        Auth::logout();
        $request->session()->regenerate(true);

        return response()->redirectToRoute('login');
    }
}
