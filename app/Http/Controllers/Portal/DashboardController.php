<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ContentBlock;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\Setting;
use App\Models\User;
use App\Services\IpAddressActionService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DashboardController extends Controller
{
    public function index(Request $request, IpAddressActionService $actionService): Response
    {
        /** @var User $user */
        $user = $request->user();
        $clientIp = (string) $request->getClientIp();
        $ip = $user->addIp($clientIp);

        if ($ip instanceof IpAddress && $ip->internet_enabled) {
            try {
                $actionService->enableInternet($ip);
            } catch (Throwable) {
                // Firewall sync is best-effort
            }
        }

        $blocks = ContentBlock::active()->get();

        $checkUrl = Setting::get('dns.check_url');
        $warningMessage = Setting::get('dns.warning_message');

        $currentMac = $ip?->currentMac();
        $macString = $currentMac?->mac_address;
        $ipv6 = $this->resolveIpv6ForMac($currentMac);

        $coverImage = Setting::get('dashboard.cover_image');

        return Inertia::render('Portal/Dashboard', [
            'blocks' => $blocks,
            'coverImage' => $coverImage ?: null,
            'blockContext' => [
                'currentIpv4' => $clientIp,
                'currentIpv6' => $ipv6,
                'internetEnabled' => $ip !== null && (bool) $ip->internet_enabled,
                'internetBlocked' => (bool) $user->internet_blocked,
                'blockedMessage' => Setting::get('portal.blocked_message', ''),
                'macAddress' => $macString,
                'dnsFilteringEnabled' => (bool) $user->dns_filtering_enabled,
                'user' => [
                    'name' => $user->nickname ?? '',
                    'params' => $user->parameters()->pluck('value', 'key')->toArray(),
                ],
            ],
            'dnsDetection' => $checkUrl ? [
                'checkUrl' => $checkUrl,
                'warningMessage' => $warningMessage ?? 'Your device is not using the event DNS servers. Please update your DNS settings.',
            ] : null,
        ]);
    }

    private function resolveIpv6ForMac(?MacAddress $mac): ?string
    {
        if (! $mac instanceof MacAddress) {
            return null;
        }

        $ipv6 = $mac->ipAddresses()
            ->orderByPivot('last_seen_at', 'desc')
            ->get()
            ->first(fn (IpAddress $ip): bool => filter_var($ip->address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false);

        return $ipv6?->address;
    }
}
