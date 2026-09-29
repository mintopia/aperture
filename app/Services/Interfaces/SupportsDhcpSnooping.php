<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use Illuminate\Support\Collection;

interface SupportsDhcpSnooping
{
    /** @return Collection<int, array{ip: string, mac: string, vlan: int, interface: string, lease_seconds: int}> */
    public function getDhcpSnoopingBindings(): Collection;
}
