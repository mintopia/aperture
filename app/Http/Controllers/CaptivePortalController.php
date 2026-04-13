<?php

namespace App\Http\Controllers;

use App\Models\AuthProvider;
use App\Services\Interfaces\AuthProviderInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CaptivePortalController extends Controller
{
    public function index(Request $request, AuthProviderInterface $authProvider): View
    {
        $provider = AuthProvider::whereEnabled(true)->first();
        if (! $provider) {
            abort(503, 'No authentication provider configured');
        }

        $deviceFlow = $authProvider->initiateDeviceFlow($provider->code);

        $qrOptions = new QROptions([
            'outputType' => QRCode::OUTPUT_MARKUP_SVG,
            'svgUseCssProperties' => false,
        ]);
        $qrCode = (new QRCode($qrOptions))->render($deviceFlow->verificationUriComplete ?? $deviceFlow->verificationUri);

        return view('captive.login', [
            'deviceCode' => $deviceFlow->deviceCode,
            'userCode' => $deviceFlow->userCode,
            'verificationUri' => $deviceFlow->verificationUri,
            'qrCode' => $qrCode,
            'expiresIn' => $deviceFlow->expiresIn,
            'interval' => $deviceFlow->interval,
        ]);
    }

    public function interstitial(): View
    {
        return view('captive.interstitial');
    }
}
