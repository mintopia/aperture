<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Interfaces\NetworkInventoryInterface;
use App\Services\ValueObjects\PortDetail;
use App\Services\ValueObjects\ResolvedPort;
use Illuminate\Cache\Repository;
use Illuminate\Support\Collection;

class CachedNetworkInventoryService implements NetworkInventoryInterface
{
    public function __construct(
        protected NetworkInventoryInterface $inner,
        protected Repository $cache,
        protected int $ttlMinutes = 5,
    ) {}

    public function getArpTable(): Collection
    {
        return $this->cache->remember(
            'network_inventory.arp',
            $this->ttlMinutes * 60,
            fn (): Collection => $this->inner->getArpTable(),
        );
    }

    public function getForwardingDatabase(): Collection
    {
        return $this->cache->remember(
            'network_inventory.fdb',
            $this->ttlMinutes * 60,
            fn (): Collection => $this->inner->getForwardingDatabase(),
        );
    }

    public function getIpv6Neighbors(): Collection
    {
        return $this->cache->remember(
            'network_inventory.ipv6_neighbors',
            $this->ttlMinutes * 60,
            fn (): Collection => $this->inner->getIpv6Neighbors(),
        );
    }

    public function getDeviceList(): Collection
    {
        return $this->cache->remember(
            'network_inventory.devices',
            $this->ttlMinutes * 60,
            fn (): Collection => $this->inner->getDeviceList(),
        );
    }

    public function resolveIpToPort(string $ipAddress): ?ResolvedPort
    {
        return $this->cache->remember(
            'network_inventory.resolve.'.$ipAddress,
            $this->ttlMinutes * 60,
            fn (): ?ResolvedPort => $this->inner->resolveIpToPort($ipAddress),
        );
    }

    public function getPortDetail(string $portId): ?PortDetail
    {
        return $this->cache->remember(
            'network_inventory.port_detail.'.$portId,
            $this->ttlMinutes * 60,
            fn (): ?PortDetail => $this->inner->getPortDetail($portId),
        );
    }
}
