<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\ValueObjects\ForwardingEntry;
use Illuminate\Support\Collection;

interface PortMacInterface
{
    /** @return Collection<int, ForwardingEntry> */
    public function getForwardingDatabase(): Collection;
}
