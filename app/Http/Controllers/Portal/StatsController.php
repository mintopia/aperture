<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\IpAddress;
use App\Services\Interfaces\IpBandwidthInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    public function bandwidth(Request $request, IpBandwidthInterface $ipBandwidth): JsonResponse
    {
        $clientIp = $request->ip() ?? '127.0.0.1';
        $range = $request->query('range', '24h');

        $ips = $this->resolveIpsForMac($clientIp);

        $bandwidth = $ipBandwidth->getIpBandwidth($ips, is_string($range) ? $range : '24h');

        return response()->json([
            'timestamps' => $bandwidth->timestamps,
            'download' => $bandwidth->download,
            'upload' => $bandwidth->upload,
            'totalReceived' => $bandwidth->received,
            'totalSent' => $bandwidth->sent,
        ])->header('Cache-Control', 'no-store');
    }

    /** @return string|string[] */
    private function resolveIpsForMac(string $clientIp): string|array
    {
        $ipModel = IpAddress::where('address', $clientIp)->first();
        if ($ipModel === null) {
            return $clientIp;
        }

        $mac = $ipModel->currentMac();
        if ($mac === null) {
            return $clientIp;
        }

        $addresses = $mac->ipAddresses()
            ->pluck('address')
            ->all();

        return count($addresses) > 1 ? $addresses : $clientIp;
    }
}
