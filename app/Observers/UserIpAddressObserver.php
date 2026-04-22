<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\UserIpAddress;
use App\Services\IpPolicyService;

class UserIpAddressObserver
{
    public function __construct(
        protected IpPolicyService $policyService,
    ) {}

    public function deleted(UserIpAddress $userIp): void
    {
        $ip = $userIp->ip;

        $this->policyService->applyDefaults($ip);
    }
}
