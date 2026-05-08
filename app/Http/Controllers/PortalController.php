<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\IntegrationConfig;
use App\Models\IpAddress;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ipv6JwtService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PortalController extends Controller
{
    public function index(Request $request): View
    {
        $clientIp = (string) $request->getClientIp();
        /** @var User $user */
        $user = $request->user();
        $ip = $user->addIp($clientIp);

        $dbConfig = IntegrationConfig::getAll('ipv6');
        $ipv6DetectionEndpoint = $dbConfig['detection_endpoint'] ?? '';

        $dnsCheckUrl = Setting::get('dns.check_url', '');

        return view('portal', [
            'ip' => $ip,
            'ipv6DetectionEndpoint' => $ipv6DetectionEndpoint,
            'dnsCheckUrl' => $dnsCheckUrl,
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $clientIp = (string) $request->getClientIp();
        /** @var User $user */
        $user = $request->user();
        $this->ensureInternetEnabled($user);
        $ip = $user->addIp($clientIp);

        return response()->json((object) [
            'ip' => $clientIp,
            'internetEnabled' => $ip !== null && (bool) $ip->internet_enabled,
        ]);
    }

    public function ipv6(Request $request, Ipv6JwtService $jwtService): JsonResponse
    {
        $request->validate(['token' => 'required|string']);

        $dbConfig = IntegrationConfig::getAll('ipv6');
        $jwksUrl = $dbConfig['jwks_url'] ?? '';

        if ($jwksUrl === '') {
            return response()->json(['error' => 'IPv6 detection not configured'], 503);
        }

        try {
            $ipv6 = $jwtService->verifyAndExtract($request->input('token'), $jwksUrl);
        } catch (Throwable $throwable) {
            return response()->json(['error' => 'Invalid token'], 422);
        }

        /** @var User $user */
        $user = $request->user();
        $ip = $user->addIp($ipv6);

        if ($ip instanceof IpAddress) {
            $clientIpRecord = IpAddress::whereAddress((string) $request->getClientIp())->first();
            $mac = $clientIpRecord?->currentMac();

            if ($mac !== null) {
                $existing = $ip->macAddresses()->where('mac_addresses.id', $mac->id)->first();

                if ($existing !== null) {
                    $ip->macAddresses()->updateExistingPivot($mac->id, [
                        'last_seen_at' => now(),
                    ]);
                } else {
                    $ip->macAddresses()->attach($mac, [
                        'source' => 'ipv6_detection',
                        'last_seen_at' => now(),
                    ]);

                    AuditLog::record(
                        action: 'ip_mac.linked',
                        subject: $ip,
                        related: $mac,
                        process: 'ipv6_detection',
                        metadata: ['client_ip' => (string) $request->getClientIp()],
                    );
                }
            }
        }

        return response()->json((object) [
            'ip' => $ipv6,
            'internetEnabled' => $ip !== null && (bool) $ip->internet_enabled,
        ]);
    }

    private function ensureInternetEnabled(User $user): void
    {
        if ($user->internet_blocked || $user->internet_enabled) {
            return;
        }

        $user->internet_enabled = true;
        $user->save();
    }
}
