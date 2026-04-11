<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalController extends Controller
{
    public function index(Request $request): View
    {
        $clientIp = (string) $request->getClientIp();
        /** @var User $user */
        $user = $request->user();
        $ip = $user->addIp($clientIp);
        if (! $user->blocked) {
            $ip->allow(true);
        }

        return view('portal', [
            'ip' => $ip,
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $clientIp = (string) $request->getClientIp();
        /** @var User $user */
        $user = $request->user();
        $ip = $user->addIp($clientIp);

        return response()->json((object) [
            'ip' => $ip->address,
            'allowed' => (bool) $ip->allowed,
        ]);
    }

    public function ipv6(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $ip = $user->addIp($request->input('ipv6'));
        if (! $user->blocked) {
            $ip->allow(true);
        }

        return response()->json((object) [
            'ip' => $ip->address,
            'allowed' => (bool) $ip->allowed,
        ]);
    }
}
