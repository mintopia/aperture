<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\NetworkScan\ApplyOuiPolicyStep;
use App\Jobs\NetworkScan\LinkIpMacStep;
use App\Jobs\NetworkScan\LinkSwitchPortMacsStep;
use App\Jobs\NetworkScan\PersistDhcpLeasesStep;
use App\Jobs\NetworkScan\PersistIpsStep;
use App\Jobs\NetworkScan\PersistMacsStep;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Interfaces\PortMacInterface;
use App\Services\NetworkRangeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScanNetworkDevices implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(
        ?DhcpInterface $dhcp = null,
        ?IpMacResolverInterface $ipMac = null,
        ?PortMacInterface $portMac = null,
        ?NetworkRangeService $rangeService = null,
        ?PersistMacsStep $persistMacs = null,
        ?PersistIpsStep $persistIps = null,
        ?LinkIpMacStep $linkIpMac = null,
        ?PersistDhcpLeasesStep $persistDhcpLeases = null,
        ?LinkSwitchPortMacsStep $linkSwitchPortMacs = null,
        ?ApplyOuiPolicyStep $applyOuiPolicy = null,
    ): void {
        $dhcp ??= app(DhcpInterface::class);
        $ipMac ??= app(IpMacResolverInterface::class);
        $portMac ??= app(PortMacInterface::class);
        $rangeService ??= app(NetworkRangeService::class);
        $persistMacs ??= new PersistMacsStep;
        $persistIps ??= new PersistIpsStep;
        $linkIpMac ??= new LinkIpMacStep;
        $persistDhcpLeases ??= new PersistDhcpLeasesStep;
        $linkSwitchPortMacs ??= new LinkSwitchPortMacsStep;
        $applyOuiPolicy ??= new ApplyOuiPolicyStep;

        $leases = $dhcp->getLeases();
        $arpEntries = $ipMac->getArpTable();
        $forwardingEntries = $portMac->getForwardingDatabase();

        $persistMacs($leases, $arpEntries, $forwardingEntries);
        $persistIps($leases, $arpEntries, $rangeService);
        $linkIpMac($leases, $arpEntries, $rangeService);
        $persistDhcpLeases($leases, $rangeService);
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
