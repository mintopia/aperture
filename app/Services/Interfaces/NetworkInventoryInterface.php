<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\ValueObjects\ArpEntry;
use App\Services\ValueObjects\ForwardingEntry;
use App\Services\ValueObjects\NetworkDevice;
use App\Services\ValueObjects\PortDetail;
use App\Services\ValueObjects\ResolvedPort;
use Illuminate\Support\Collection;

interface NetworkInventoryInterface
{
    /** @return Collection<int, ForwardingEntry> */
    public function getForwardingDatabase(): Collection;

    /** @return Collection<int, ArpEntry> */
    public function getArpTable(): Collection;

    public function resolveIpToPort(string $ipAddress): ?ResolvedPort;

    /** @return Collection<int, NetworkDevice> */
    public function getDeviceList(): Collection;

    /** @return Collection<int, ArpEntry> */
    public function getIpv6Neighbors(): Collection;

    public function getPortDetail(string $portId): ?PortDetail;
}
