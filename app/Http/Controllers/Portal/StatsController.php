<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Interfaces\TrafficMonitorInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    public function bandwidth(Request $request, TrafficMonitorInterface $trafficMonitor): JsonResponse
    {
        $ip = $request->ip() ?? '127.0.0.1';
        $range = $request->query('range', '24h');

        $bandwidth = $trafficMonitor->getUserBandwidth($ip, is_string($range) ? $range : '24h');

        return response()->json([
            'timestamps' => $bandwidth->timestamps,
            'download' => $bandwidth->download,
            'upload' => $bandwidth->upload,
            'totalReceived' => $bandwidth->received,
            'totalSent' => $bandwidth->sent,
        ]);
    }
}
