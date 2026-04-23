<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\IntegrationConfig;
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

        return view('portal', [
            'ip' => $ip,
            'ipv6DetectionEndpoint' => $ipv6DetectionEndpoint,
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $clientIp = (string) $request->getClientIp();
        /** @var User $user */
        $user = $request->user();
        $ip = $user->addIp($clientIp);

        return response()->json((object) [
            'ip' => $clientIp,
            'internetEnabled' => (bool) ($ip?->internet_enabled ?? false),
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

        return response()->json((object) [
            'ip' => $ip?->address,
            'internetEnabled' => (bool) ($ip?->internet_enabled ?? false),
        ]);
    }
}
