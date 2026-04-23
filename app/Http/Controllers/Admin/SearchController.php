<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IpAddress;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:2|max:100',
        ]);

        $escaped = str_replace(['%', '_'], ['\%', '\_'], $request->input('q'));
        $pattern = sprintf('%%%s%%', $escaped);

        $users = User::where('nickname', 'like', $pattern)
            ->orWhere('email', 'like', $pattern)
            ->limit(5)
            ->get(['id', 'nickname', 'email']);

        $ips = IpAddress::where('address', 'like', $pattern)
            ->limit(5)
            ->get(['id', 'address', 'internet_enabled']);

        return response()->json([
            'users' => $users,
            'ips' => $ips,
        ]);
    }
}
