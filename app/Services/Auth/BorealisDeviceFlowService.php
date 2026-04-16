<?php

namespace App\Services\Auth;

use App\Services\Borealis\RequestException;
use App\Services\BorealisService;
use App\Services\Interfaces\AuthProviderInterface;
use RuntimeException;
use stdClass;

class BorealisDeviceFlowService implements AuthProviderInterface
{
    protected ?stdClass $cachedUserData = null;

    public function __construct(protected BorealisService $borealis) {}

    public function initiateDeviceFlow(string $scope): DeviceFlowResponse
    {
        $response = $this->borealis->getDeviceCodeRaw($scope);

        return new DeviceFlowResponse(
            verificationUri: $response->verification_uri,
            deviceCode: $response->device_code,
            userCode: $response->user_code,
            expiresIn: $response->expires_in,
            interval: $response->interval,
            verificationUriComplete: $response->verification_uri_complete ?? null,
        );
    }

    public function pollDeviceFlow(string $deviceCode): ?AuthResult
    {
        try {
            $response = $this->borealis->check($deviceCode);
        } catch (RequestException $requestException) {
            if ($requestException->getMessage() === 'authorization_pending' || $requestException->getMessage() === 'slow_down') {
                return null;
            }

            throw $requestException;
        }

        if (isset($response->user)) {
            $this->cachedUserData = $response->user;
        }

        return new AuthResult(
            accessToken: $response->access_token,
            tokenType: $response->token_type ?? 'Bearer',
            expiresIn: $response->expires_in,
            refreshToken: $response->refresh_token ?? null,
        );
    }

    public function getUserInfo(string $accessToken): UserInfo
    {
        if (! $this->cachedUserData instanceof stdClass) {
            throw new RuntimeException('User info not available. pollDeviceFlow() must be called first.');
        }

        return new UserInfo(
            id: $this->cachedUserData->id,
            nickname: $this->cachedUserData->nickname,
            email: $this->cachedUserData->email ?? null,
            avatarUrl: $this->cachedUserData->avatar_url ?? null,
        );
    }
}
