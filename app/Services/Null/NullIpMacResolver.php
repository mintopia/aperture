<?php

declare(strict_types=1);

namespace App\Services\Null;

use App\Services\Interfaces\IpMacResolverInterface;
use Illuminate\Support\Collection;

class NullIpMacResolver implements IpMacResolverInterface
{
    public function getArpTable(): Collection
    {
        return collect();
    }
}
