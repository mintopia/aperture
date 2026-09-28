<?php

declare(strict_types=1);

namespace App\Services\Auth\E2e;

use App\Services\Auth\AuthResult;
use App\Services\Auth\DeviceFlowResponse;
use App\Services\Auth\UserInfo;
use App\Services\Interfaces\AuthProviderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Stand-in for Borealis, bound only when APP_ENV=playwright. */
class FakeDeviceFlowService implements AuthProviderInterface
{
    public const APPROVED_KEY = 'e2e_device_approved:';

    public const USER_CODE_KEY = 'e2e_user_code:';

    public function initiateDeviceFlow(string $scope): DeviceFlowResponse
    {
        $deviceCode = 'e2e-'.Str::random(24);
        $userCode = Str::upper(Str::random(4)).'-'.random_int(1000, 9999);
        Cache::put(self::USER_CODE_KEY.$userCode, $deviceCode, now()->addMinutes(10));

        $verificationUri = url('/e2e/device');

        return new DeviceFlowResponse(
            verificationUri: $verificationUri,
            deviceCode: $deviceCode,
            userCode: $userCode,
            expiresIn: 600,
            interval: 1,
            verificationUriComplete: $verificationUri.'?user_code='.$userCode,
        );
    }

    public function pollDeviceFlow(string $deviceCode): ?AuthResult
    {
        if (! Cache::has(self::APPROVED_KEY.$deviceCode)) {
            return null;
        }

        return new AuthResult(accessToken: 'e2e-token', tokenType: 'Bearer', expiresIn: 3600, refreshToken: 'e2e-refresh');
    }

    public function getUserInfo(string $accessToken): UserInfo
    {
        return new UserInfo(id: 'e2e-borealis-user', nickname: 'playwright-captive', email: 'playwright-captive@example.test');
    }
}
