<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\IpMacObserved;
use App\Http\Requests\PortalTokenRequest;
use App\Models\AuditLog;
use App\Models\IntegrationConfig;
use App\Models\IpAddress;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ipv6JwtService;
use App\Services\UserNetworkAssociationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PortalController extends Controller
{
    public function index(Request $request, UserNetworkAssociationService $associations): View
    {
        $clientIp = (string) $request->getClientIp();
        /** @var User $user */
        $user = $request->user();
        $ip = $associations->addIp($user, $clientIp);

        $dbConfig = IntegrationConfig::getAll('ipv6');
        $ipv6DetectionEndpoint = $dbConfig['detection_endpoint'] ?? '';

        $dnsCheckUrl = Setting::get('dns.check_url', '');

        return view('portal', [
            'ip' => $ip,
            'ipv6DetectionEndpoint' => $ipv6DetectionEndpoint,
            'ipv6SessionBinding' => Ipv6JwtService::sessionBinding($request->session()->getId()),
            'dnsCheckUrl' => $dnsCheckUrl,
        ]);
    }

    public function status(Request $request, UserNetworkAssociationService $associations): JsonResponse
    {
        $clientIp = (string) $request->getClientIp();
        /** @var User $user */
        $user = $request->user();
        $this->ensureInternetEnabled($user);
        $ip = $associations->addIp($user, $clientIp);

        return response()->json((object) [
            'ip' => $clientIp,
            'internetEnabled' => $ip instanceof IpAddress && $ip->isInternetAllowed(),
        ]);
    }

    public function ipv6(PortalTokenRequest $request, Ipv6JwtService $jwtService, UserNetworkAssociationService $associations): JsonResponse
    {
        $dbConfig = IntegrationConfig::getAll('ipv6');
        $jwksUrl = $dbConfig['jwks_url'] ?? '';

        if ($jwksUrl === '') {
            return response()->json(['error' => 'IPv6 detection not configured'], 503);
        }

        try {
            $ipv6 = $jwtService->verifyAndExtract($request->input('token'), $jwksUrl, $request->session()->getId());
        } catch (Throwable $throwable) {
            return response()->json(['error' => 'Invalid token'], 422);
        }

        /** @var User $user */
        $user = $request->user();
        $ip = $associations->addIp($user, $ipv6);

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

                event(new IpMacObserved($ip, $mac, 'ipv6_detection', 'ipv6_detection'));
            }
        }

        return response()->json((object) [
            'ip' => $ipv6,
            'internetEnabled' => $ip instanceof IpAddress && $ip->isInternetAllowed(),
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
