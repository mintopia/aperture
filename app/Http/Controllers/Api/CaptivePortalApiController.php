<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IpAddress;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CaptivePortalApiController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $clientIp = IpAddress::normalize((string) $request->getClientIp());
        $ip = IpAddress::where('address', $clientIp)->first();

        $captive = $ip === null || ! $ip->internet_enabled;

        $payload = [
            'captive' => $captive,
        ];

        $userPortalUrl = Setting::get('captive_portal_api.user_portal_url');
        if ($userPortalUrl !== null && $userPortalUrl !== '') {
            $payload['user-portal-url'] = $userPortalUrl;
        }

        $venueInfoUrl = Setting::get('captive_portal_api.venue_info_url');
        if ($venueInfoUrl !== null && $venueInfoUrl !== '') {
            $payload['venue-info-url'] = $venueInfoUrl;
        }

        $canExtendSession = Setting::get('captive_portal_api.can_extend_session');
        if ($canExtendSession !== null) {
            $payload['can-extend-session'] = $canExtendSession === '1';
        }

        return response()
            ->json($payload)
            ->header('Content-Type', 'application/captive+json')
            ->header('Cache-Control', 'private, no-store');
    }
}
