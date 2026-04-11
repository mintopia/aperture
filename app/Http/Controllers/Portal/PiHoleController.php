<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Interfaces\DnsBlockingInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PiHoleController extends Controller
{
    public function toggle(Request $request, DnsBlockingInterface $dnsBlocking): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $clientIp = (string) $request->getClientIp();

        $ownsIp = $user->ips()
            ->whereHas('ip', fn ($q) => $q->where('address', $clientIp))
            ->exists();

        if (! $ownsIp) {
            return response()->json(['error' => 'IP address not associated with your account.'], 403);
        }

        if ($dnsBlocking->isEnabledForIp($clientIp)) {
            $dnsBlocking->disableForIp($clientIp);
            $enabled = false;
        } else {
            $dnsBlocking->enableForIp($clientIp);
            $enabled = true;
        }

        return response()->json(['enabled' => $enabled]);
    }
}
