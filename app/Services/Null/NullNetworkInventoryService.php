<?php

declare(strict_types=1);

namespace App\Services\Null;

use App\Services\Interfaces\NetworkInventoryInterface;
use App\Services\ValueObjects\ArpEntry;
use App\Services\ValueObjects\ForwardingEntry;
use App\Services\ValueObjects\NetworkDevice;
use App\Services\ValueObjects\PortDetail;
use App\Services\ValueObjects\ResolvedPort;
use Illuminate\Support\Collection;

class NullNetworkInventoryService implements NetworkInventoryInterface
{
    /** @return Collection<int, ForwardingEntry> */
    public function getForwardingDatabase(): Collection
    {
        return collect();
    }

    /** @return Collection<int, ArpEntry> */
    public function getArpTable(): Collection
    {
        return collect();
    }

    public function resolveIpToPort(string $ipAddress): ?ResolvedPort
    {
        return null;
    }

    /** @return Collection<int, NetworkDevice> */
    public function getDeviceList(): Collection
    {
        return collect();
    }

    /** @return Collection<int, ArpEntry> */
    public function getIpv6Neighbors(): Collection
    {
        return collect();
    }

    public function getPortDetail(string $portId): ?PortDetail
    {
        return null;
    }
}
