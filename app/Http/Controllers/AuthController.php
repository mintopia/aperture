<?php

namespace App\Http\Controllers;

use App\Http\Resources\DeviceCodeResource;
use App\Models\AuthProvider;
use App\Services\Borealis\DeviceCode;
use App\Services\Borealis\DeviceCodeStatus;
use App\Services\BorealisService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Two\InvalidStateException;

class AuthController extends Controller
{
    public function login(Request $request): View
    {
        if ($request->has('fail')) {
            session()->now('errorMessage', 'Unable to log you in, please try again');
        }

        $providers = AuthProvider::whereEnabled(true)->get();

        return view('login', [
            'providers' => $providers,
        ]);
    }

    public function login_provider(BorealisService $borealis, AuthProvider $provider): View|RedirectResponse
    {
        if (! config('aperture.borealis.enabled') || ! $provider->getBackend()->supportsBorealis()) {
            return response()
                ->redirectToRoute('login.redirect', ['provider' => $provider->code]);
        }

        /**
         * @var ?DeviceCode $deviceCode
         */
        $deviceCode = session()->get('deviceCode');
        if ($deviceCode === null || $deviceCode->expiresAt->isBefore(CarbonImmutable::now()->addMinutes(2))) {
            $deviceCode = $borealis->getDeviceCode($provider);
            session()->put('deviceCode', $deviceCode);
        }

        return view('login.provider', [
            'provider' => $provider,
            'deviceCode' => $deviceCode,
        ]);
    }

    public function login_check(Request $request): DeviceCodeResource
    {
        /** @var ?DeviceCode $deviceCode */
        $deviceCode = session()->get('deviceCode');
        if ($deviceCode === null) {
            return new DeviceCodeResource(null);
        }

        $deviceCode->check();
        if ($deviceCode->status === DeviceCodeStatus::dcsSuccessful) {
            $user = $deviceCode->getUser();
            if ($user !== null) {
                $clientIp = $request->getClientIp();
                if ($clientIp !== null) {
                    $user->addIp($clientIp);
                }

                Auth::login($user);
                session()->forget('deviceCode');
            }
        }

        return new DeviceCodeResource($deviceCode);
    }

    public function redirect(AuthProvider $provider): mixed
    {
        return $provider->getBackend()->redirect();
    }

    public function handle(Request $request, AuthProvider $provider): RedirectResponse
    {
        try {
            $user = $provider->getBackend()->user();
        } catch (InvalidStateException $invalidStateException) {
            return response()->redirectToRoute('login');
        }

        $clientIp = $request->getClientIp();
        if ($clientIp !== null) {
            $user->addIp($clientIp);
        }

        Auth::login($user);

        return response()->redirectToRoute('home');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->regenerate(true);

        return response()->redirectToRoute('home');
    }
}
