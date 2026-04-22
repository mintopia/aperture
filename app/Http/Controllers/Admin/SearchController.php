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
        $query = $request->input('q', '');

        if (strlen($query) < 2) {
            return response()->json(['error' => 'Query must be at least 2 characters.'], 422);
        }

        $users = User::where('nickname', 'like', sprintf('%%%s%%', $query))
            ->orWhere('email', 'like', sprintf('%%%s%%', $query))
            ->limit(5)
            ->get(['id', 'nickname', 'email']);

        $ips = IpAddress::where('address', 'like', sprintf('%%%s%%', $query))
            ->limit(5)
            ->get(['id', 'address', 'internet_enabled']);

        return response()->json([
            'users' => $users,
            'ips' => $ips,
        ]);
    }
}
