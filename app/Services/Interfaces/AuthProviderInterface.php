<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\Auth\AuthResult;
use App\Services\Auth\DeviceFlowResponse;
use App\Services\Auth\UserInfo;

interface AuthProviderInterface
{
    public function initiateDeviceFlow(string $scope): DeviceFlowResponse;

    public function pollDeviceFlow(string $deviceCode): ?AuthResult;

    public function getUserInfo(string $accessToken): UserInfo;
}
