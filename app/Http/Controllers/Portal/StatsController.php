<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\IpAddress;
use App\Models\User;
use App\Services\NtopNgService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    public function bandwidth(Request $request, NtopNgService $ntopNg): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $ipAddresses = $user->ips()->with('ip')->get()->pluck('ip');

        $stats = $ipAddresses->map(function (IpAddress $ip): array {
            return [
                'address' => $ip->address,
                'received' => $ip->received,
                'sent' => $ip->sent,
            ];
        });

        return response()->json([
            'stats' => $stats,
            'totalReceived' => $ipAddresses->sum('received'),
            'totalSent' => $ipAddresses->sum('sent'),
        ]);
    }
}
