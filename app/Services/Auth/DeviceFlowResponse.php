<?php

declare(strict_types=1);

namespace App\Services\Auth;

class DeviceFlowResponse
{
    public function __construct(
        public string $verificationUri,
        public string $deviceCode,
        public string $userCode,
        public int $expiresIn,
        public int $interval,
        public ?string $verificationUriComplete = null,
    ) {}
}
