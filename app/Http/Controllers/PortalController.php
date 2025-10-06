<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PortalController extends Controller
{
    public function index(Request $request)
    {
        $ip = $request->user()->addIp($request->getClientIp());
        if (!$request->user()->blocked) {
            $ip->allow(true);
        }
        return view('portal', [
            'ip' => $ip,
        ]);
    }

    public function status(Request $request)
    {
        $ip = $request->user()->addIp($request->getClientIp());
        return response()->json((object)[
            'ip' => $ip->address,
            'allowed' => (bool) $ip->allowed,
        ]);
    }

    public function ipv6(Request $request)
    {
        $ip = $request->user()->addIp($request->input('ipv6'));
        if (!$request->user()->blocked) {
            $ip->allow(true);
        }
        return response()->json((object)[
            'ip' => $ip->address,
            'allowed' => (bool) $ip->allowed,
        ]);
    }
}
