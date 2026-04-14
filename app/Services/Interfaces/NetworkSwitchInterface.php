<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\ValueObjects\ForwardingEntry;
use App\Services\ValueObjects\PortStatistics;
use App\Services\ValueObjects\PortStatus;
use Illuminate\Support\Collection;

interface NetworkSwitchInterface
{
    public function getPortStatus(string $portId): PortStatus;

    /** @return Collection<int, PortStatus> */
    public function getAllPorts(): Collection;

    public function shutdownPort(string $portId): bool;

    public function enablePort(string $portId): bool;

    public function getPortStatistics(string $portId): PortStatistics;

    /** @return Collection<int, ForwardingEntry> */
    public function getForwardingDatabase(): Collection;
}
