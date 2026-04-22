<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\SwitchConfig;
use App\Models\UserIpAddress;
use App\Services\Interfaces\FirewallBackendInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use App\Services\ValueObjects\PortDetail;
use stdClass;
use Throwable;

class IpAddressActionService
{
    public function __construct(
        protected FirewallBackendInterface $firewall,
        protected SwitchServiceFactory $switchFactory,
        protected MacAddressResolverInterface $macResolver,
        protected NtopNgService $ntopng,
    ) {}

    public function enableInternet(IpAddress $ip): void
    {
        $description = $ip->comment;
        /** @var UserIpAddress|null $userIp */
        $userIp = $ip->users()->first();
        if ($userIp) {
            $description = $userIp->user->nickname;
        }

        $this->firewall->updateIp($ip->address, (string) $description);

        try {
            $mac = $this->macResolver->resolveIpToMac($ip->address);
            if ($mac !== null) {
                $macAddress = MacAddress::firstOrCreate(
                    ['mac_address' => $mac],
                    ['source' => 'auth', 'allowed' => true, 'allowed_at' => now()],
                );
                $ip->mac_address_id = (int) $macAddress->id;
                $ip->saveQuietly();

                if (! $macAddress->allowed) {
                    $macAddress->allowed = true;
                    $macAddress->allowed_at = now();
                    $macAddress->save();
                }

                if ($macAddress->user_id === null && $userIp?->user) {
                    $macAddress->user_id = (int) $userIp->user->id;
                    $macAddress->save();
                }
            }
        } catch (Throwable) {
            // MAC resolution is best-effort
        }
    }

    public function disableInternet(IpAddress $ip): void
    {
        $this->firewall->removeIp($ip->address);
    }

    public function enableRateLimit(IpAddress $ip): void
    {
        $this->firewall->limitIp($ip->address);
    }

    public function disableRateLimit(IpAddress $ip): void
    {
        $this->firewall->unlimitIp($ip->address);
    }

    public function shutPort(IpAddress $ip): void
    {
        $result = $this->resolveAdapterAndPort($ip);
        if ($result === null) {
            return;
        }

        [$adapter, $portName] = $result;
        $adapter->shutdownPort($portName);
    }

    public function unshutPort(IpAddress $ip): void
    {
        $result = $this->resolveAdapterAndPort($ip);
        if ($result === null) {
            return;
        }

        [$adapter, $portName] = $result;
        $adapter->enablePort($portName);
    }

    /**
     * @return array{NetworkSwitchInterface, string}|null
     */
    private function resolveAdapterAndPort(IpAddress $ip): ?array
    {
        $portInfo = $ip->getPortInfo();
        if (! $portInfo instanceof PortDetail) {
            return null;
        }

        $switchConfig = SwitchConfig::where('hostname', $portInfo->hostname)->first();
        if (! $switchConfig) {
            return null;
        }

        $switchPort = $switchConfig->switchPorts()->where('port_name', $portInfo->interface)->first();
        if (! $switchPort) {
            return null;
        }

        return [$this->switchFactory->make($switchConfig), $portInfo->interface];
    }

    public function updateUsage(IpAddress $ip): void
    {
        $stats = $this->ntopng->getStats($ip->address);
        $attr = 'bytes.rcvd';
        $ip->received = $stats->rsp->$attr;
        $attr = 'bytes.sent';
        $ip->sent = $stats->rsp->$attr;
        $ip->save();
    }

    public function getStats(IpAddress $ip): stdClass
    {
        return $this->ntopng->getStats($ip->address);
    }
}
