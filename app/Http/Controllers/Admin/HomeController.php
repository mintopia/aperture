<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ResetAperture;
use App\Models\IpAddress;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'totalUsers' => User::count(),
            'onlineUsers' => User::whereHas('ips', fn ($q) => $q->whereHas('ip', fn ($q2) => $q2->where('allowed', true)))->count(), // @phpstan-ignore argument.templateType
            'totalIps' => IpAddress::count(),
            'allowedIps' => IpAddress::where('allowed', true)->count(),
        ]);
    }

    public function reset(): RedirectResponse
    {
        ResetAperture::dispatch();

        return redirect()->route('admin.home')->with('success', 'Portal reset initiated.');
    }
}
