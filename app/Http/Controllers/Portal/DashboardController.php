<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ContentBlock;
use App\Models\Setting;
use App\Models\User;
use App\Services\LibreNms\LibreNmsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DashboardController extends Controller
{
    public function __construct(
        private readonly LibreNmsService $networkInventory,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $clientIp = (string) $request->getClientIp();
        $ip = $user->addIp($clientIp);
        $blocks = ContentBlock::active()->get();

        $checkUrl = Setting::get('dns.check_url');
        $warningMessage = Setting::get('dns.warning_message');

        $currentMac = $ip?->currentMac();
        $macString = $currentMac?->mac_address;
        $ipv6 = $ip !== null ? $this->resolveIpv6ForMac($macString) : null;

        return Inertia::render('Portal/Dashboard', [
            'blocks' => $blocks,
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

    private function resolveIpv6ForMac(?string $mac): string
    {
        if ($mac === null || $mac === '') {
            return '';
        }

        try {
            $neighbors = $this->networkInventory->getIpv6Neighbors();

            $match = $neighbors->first(
                fn ($entry): bool => strcasecmp($entry->mac, $mac) === 0
            );

            return $match->ip ?? '';
        } catch (Throwable) {
            return '';
        }
    }
}
