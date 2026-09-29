<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\IpMacObserved;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\UserIpAddress;
use App\Services\Interfaces\CaptivePortalInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\Interfaces\RateLimitingInterface;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

class IpAddressActionService
{
    public function __construct(
        protected CaptivePortalInterface $captivePortal,
        protected RateLimitingInterface $rateLimiter,
        protected SwitchServiceFactory $switchFactory,
        protected MacAddressResolverInterface $macResolver,
    ) {}

    public function enableInternet(IpAddress $ip): void
    {
        $description = $ip->comment;
        /** @var UserIpAddress|null $userIp */
        $userIp = $ip->users()->first();
        if ($userIp) {
            $description = $userIp->user->nickname;
        }

        $this->captivePortal->addIp($ip->address, (string) $description);

        try {
            $mac = $this->macResolver->resolveIpToMac($ip->address);
            $normalizedMac = MacAddress::normalize($mac);
            if ($normalizedMac !== null) {
                $macAddress = MacAddress::firstOrCreate(
                    ['mac_address' => $normalizedMac],
                    ['source' => 'auth'],
                );

                $ip->macAddresses()->syncWithoutDetaching([
                    $macAddress->id => ['source' => 'auth', 'last_seen_at' => now()],
                ]);

                if ($macAddress->user_id === null && $userIp?->user) {
                    $macAddress->user_id = (int) $userIp->user->id;
                    $macAddress->save();
                }

                event(new IpMacObserved($ip, $macAddress, 'auth', 'auth'));
            }
        } catch (Throwable $throwable) {
            Log::warning('MAC resolution/cascade failed during enableInternet', [
                'ip' => $ip->address,
                'exception' => $throwable,
            ]);
        }
    }

    public function disableInternet(IpAddress $ip): void
    {
        $this->captivePortal->removeIp($ip->address);
    }

    public function enableRateLimit(IpAddress $ip): void
    {
        $this->rateLimiter->limitIp($ip->address);
    }

    public function disableRateLimit(IpAddress $ip): void
    {
        $this->rateLimiter->unlimitIp($ip->address);
    }
}
