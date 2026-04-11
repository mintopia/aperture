<?php

namespace App\Http\Controllers;

use App\Models\AuthProvider;
use App\Models\User;
use App\Models\UserAuthentication;
use App\Services\Auth\AuthResult;
use App\Services\Auth\UserInfo;
use App\Services\Interfaces\AuthProviderInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DeviceAuthController extends Controller
{
    public function initiate(Request $request, AuthProviderInterface $authProvider): JsonResponse
    {
        $request->validate([
            'provider' => ['required', 'string', 'exists:auth_providers,code'],
        ]);

        $provider = AuthProvider::whereCode($request->input('provider'))->firstOrFail();
        $response = $authProvider->initiateDeviceFlow($provider->code);

        Cache::put(
            'device_flow:'.$response->deviceCode,
            [
                'provider_code' => $provider->code,
                'status' => 'pending',
                'ip' => $request->getClientIp(),
            ],
            now()->addSeconds($response->expiresIn)
        );

        return response()->json([
            'device_code' => $response->deviceCode,
            'user_code' => $response->userCode,
            'verification_uri' => $response->verificationUri,
            'verification_uri_complete' => $response->verificationUriComplete,
            'expires_in' => $response->expiresIn,
            'interval' => $response->interval,
        ]);
    }

    public function poll(Request $request, string $deviceCode, AuthProviderInterface $authProvider): JsonResponse
    {
        $flowData = Cache::get('device_flow:'.$deviceCode);

        if (! $flowData) {
            return response()->json(['status' => 'expired'], 410);
        }

        if ($flowData['status'] === 'complete') {
            return response()->json([
                'status' => 'complete',
                'redirect' => route('home'),
            ]);
        }

        $result = $authProvider->pollDeviceFlow($deviceCode);

        if (! $result instanceof AuthResult) {
            return response()->json(['status' => 'pending']);
        }

        $userInfo = $authProvider->getUserInfo($result->accessToken);
        $provider = AuthProvider::whereCode($flowData['provider_code'])->firstOrFail();

        $user = $this->findOrCreateUser($provider, $userInfo, $result);

        $ip = $user->addIp($flowData['ip']);
        if (! $user->blocked) {
            $ip->allow(true);
        }

        Auth::login($user);

        Cache::put('device_flow:'.$deviceCode, array_merge($flowData, [
            'status' => 'complete',
            'user_id' => $user->id,
        ]), now()->addMinutes(5));

        return response()->json([
            'status' => 'complete',
            'redirect' => route('home'),
        ]);
    }

    protected function findOrCreateUser(AuthProvider $provider, UserInfo $userInfo, AuthResult $result): User
    {
        return DB::transaction(function () use ($provider, $userInfo, $result) {
            $auth = UserAuthentication::whereAuthProviderId($provider->id)
                ->whereExternalId($userInfo->id)
                ->first();

            if (! $auth) {
                $auth = new UserAuthentication;
                $auth->provider()->associate($provider);

                $user = $userInfo->email ? User::whereEmail($userInfo->email)->first() : null;
                if (! $user) {
                    $user = new User;
                    $user->email = (string) $userInfo->email;
                }
            } else {
                $user = $auth->user;
            }

            $user->nickname = $userInfo->nickname;
            $user->save();

            $auth->external_id = $userInfo->id;
            $auth->access_token = $result->accessToken;
            $auth->refresh_token = (string) $result->refreshToken;
            $auth->token_expires_at = now()->addSeconds($result->expiresIn);
            $auth->user()->associate($user);
            $auth->save();

            return $user;
        });
    }
}
