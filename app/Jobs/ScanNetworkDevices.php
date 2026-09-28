<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\NetworkScan\ApplyOuiPolicyStep;
use App\Jobs\NetworkScan\LinkIpMacStep;
use App\Jobs\NetworkScan\LinkSwitchPortMacsStep;
use App\Jobs\NetworkScan\PersistIpsStep;
use App\Jobs\NetworkScan\PersistMacsStep;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Interfaces\PortMacInterface;
use App\Services\NetworkRangeService;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScanNetworkDevices implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue(Queues::SYNC);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(
        DhcpInterface $dhcp,
        IpMacResolverInterface $ipMac,
        PortMacInterface $portMac,
        NetworkRangeService $rangeService,
        PersistMacsStep $persistMacs,
        PersistIpsStep $persistIps,
        LinkIpMacStep $linkIpMac,
        LinkSwitchPortMacsStep $linkSwitchPortMacs,
        ApplyOuiPolicyStep $applyOuiPolicy,
    ): void {

        $leases = $dhcp->getLeases();
        $arpEntries = $ipMac->getArpTable();
        $forwardingEntries = $portMac->getForwardingDatabase();

        $persistMacs($leases, $arpEntries, $forwardingEntries);
        $persistIps($leases, $arpEntries, $rangeService);
        $linkIpMac($leases, $arpEntries, $rangeService);
        $linkSwitchPortMacs($forwardingEntries);
        $applyOuiPolicy();
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ScanNetworkDevices failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
