<?php

declare(strict_types=1);

namespace App\Services\Null;

use App\Services\Interfaces\DhcpInterface;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpSnapshot;

class NullDhcpService implements DhcpInterface
{
    public function snapshot(): DhcpSnapshot
    {
        return DhcpSnapshot::unavailable();
    }

    public function getLease(string $ipAddress): ?DhcpLease
    {
        return null;
    }
}
