<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\ValueObjects\ArpEntry;
use Illuminate\Support\Collection;

interface IpMacResolverInterface
{
    /** @return Collection<int, ArpEntry> */
    public function getArpTable(): Collection;
}
