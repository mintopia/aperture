<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\IntegrationConfig;
use App\Services\Auth\AuthResult;
use App\Services\Auth\DeviceFlowUserService;
use App\Services\Interfaces\AuthProviderInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Throwable;

class CaptivePortalController extends Controller
{
    public function index(Request $request, AuthProviderInterface $authProvider): View|Response
    {
        try {
            $scope = (string) IntegrationConfig::getWithFallback('borealis', 'scope', 'discord');
            $deviceFlow = $authProvider->initiateDeviceFlow($scope);

            Cache::put(
                'device_flow:'.$deviceFlow->deviceCode,
                ['status' => 'pending', 'ip' => $request->getClientIp()],
                now()->addSeconds($deviceFlow->expiresIn)
            );

            $qrOptions = new QROptions([
                'outputType' => QRCode::OUTPUT_MARKUP_SVG,
                'svgUseCssProperties' => false,
                'outputBase64' => false,
            ]);
            $qrCode = (new QRCode($qrOptions))->render($deviceFlow->verificationUriComplete ?? $deviceFlow->verificationUri);

            return view('captive.login', [
                'serviceUnavailable' => false,
                'deviceCode' => $deviceFlow->deviceCode,
                'userCode' => $deviceFlow->userCode,
                'verificationUri' => $deviceFlow->verificationUri,
                'qrCode' => $qrCode,
                'expiresIn' => $deviceFlow->expiresIn,
                'interval' => $deviceFlow->interval,
            ]);
        } catch (Throwable $throwable) {
            report($throwable);

            return response()->view('captive.login', [
                'serviceUnavailable' => true,
                'deviceCode' => null,
                'userCode' => null,
                'verificationUri' => null,
                'qrCode' => null,
                'expiresIn' => 0,
                'interval' => 0,
            ], 503);
        }
    }

    public function poll(
        Request $request,
        string $deviceCode,
        AuthProviderInterface $authProvider,
        DeviceFlowUserService $userService,
    ): JsonResponse {
        /** @var array{status: string, ip: string|null}|null $flowData */
        $flowData = Cache::get('device_flow:'.$deviceCode);
        if (! $flowData) {
            return response()->json(['status' => 'expired'], 410);
        }

        if ($flowData['status'] === 'complete') {
            return response()->json(['status' => 'complete', 'redirect' => route('home')]);
        }

        $result = $authProvider->pollDeviceFlow($deviceCode);
        if (! $result instanceof AuthResult) {
            return response()->json(['status' => 'pending']);
        }

        $userInfo = $authProvider->getUserInfo($result->accessToken);
        $user = $userService->findOrCreateFromDeviceFlow($userInfo, $result);

        if (! $user->internet_blocked) {
            $user->internet_enabled = true;
            $user->save();
        }

        $user->addIp($flowData['ip'] ?? $request->getClientIp() ?? '0.0.0.0');

        Auth::login($user);

        Cache::put('device_flow:'.$deviceCode, array_merge($flowData, [
            'status' => 'complete',
            'user_id' => $user->id,
        ]), now()->addMinutes(5));

        return response()->json(['status' => 'complete', 'redirect' => route('home')]);
    }

    public function interstitial(): View
    {
        return view('captive.interstitial');
    }
}
