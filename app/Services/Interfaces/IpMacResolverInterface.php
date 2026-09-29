<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\ValueObjects\IpMacEntry;
use Illuminate\Support\Collection;

interface IpMacResolverInterface
{
    /** @return Collection<int, IpMacEntry> */
    public function getIpMacTable(): Collection;
}
