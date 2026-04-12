<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Interfaces\NetworkInventoryInterface;
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

    public function resolveIpToPort(string $ipAddress): ?array
    {
        return $this->cache->remember(
            'network_inventory.resolve.'.$ipAddress,
            $this->ttlMinutes * 60,
            fn (): ?array => $this->inner->resolveIpToPort($ipAddress),
        );
    }

    public function getPortDetail(string $portId): ?array
    {
        return $this->cache->remember(
            'network_inventory.port_detail.'.$portId,
            $this->ttlMinutes * 60,
            fn (): ?array => $this->inner->getPortDetail($portId),
        );
    }
}
