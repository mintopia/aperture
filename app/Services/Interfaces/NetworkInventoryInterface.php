<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use Illuminate\Support\Collection;

interface NetworkInventoryInterface
{
    /**
     * @return Collection<int, array{mac: string, port: string, vlan: int}>
     */
    public function getForwardingDatabase(): Collection;

    /**
     * @return Collection<int, array{ip: string, mac: string}>
     */
    public function getArpTable(): Collection;

    /**
     * @return array{ip: string, mac: string, port: string, switch: string}|null
     */
    public function resolveIpToPort(string $ipAddress): ?array;

    /**
     * @return Collection<int, array{hostname: string, ip: string, type: string}>
     */
    public function getDeviceList(): Collection;

    /**
     * @return Collection<int, array{ip: string, mac: string}>
     */
    public function getIpv6Neighbors(): Collection;

    /**
     * @return array{hostname: string, interface: string, status: string, adminStatus: string, speed: int}|null
     */
    public function getPortDetail(string $portId): ?array;
}
