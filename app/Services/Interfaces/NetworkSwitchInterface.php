<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use Illuminate\Support\Collection;

interface NetworkSwitchInterface
{
    /**
     * @return array{interface: string, status: string, speed: string, duplex: string, vlan: string}
     */
    public function getPortStatus(string $portId): array;

    /**
     * @return Collection<int, array{interface: string, status: string, speed: string}>
     */
    public function getAllPorts(): Collection;

    public function shutdownPort(string $portId): bool;

    public function enablePort(string $portId): bool;

    /**
     * @return array{in_bytes: int, out_bytes: int, in_errors: int, out_errors: int}
     */
    public function getPortStatistics(string $portId): array;

    /**
     * @return Collection<int, array{mac: string, port: string, vlan: int}>
     */
    public function getForwardingDatabase(): Collection;
}
