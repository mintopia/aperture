<?php

declare(strict_types=1);

namespace App\Services\Null;

use App\Services\Interfaces\PortMacInterface;
use Illuminate\Support\Collection;

class NullPortMac implements PortMacInterface
{
    public function getForwardingDatabase(): Collection
    {
        return collect();
    }
}
