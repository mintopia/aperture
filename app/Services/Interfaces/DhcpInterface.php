<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpSnapshot;

interface DhcpInterface
{
    public function snapshot(): DhcpSnapshot;

    public function getLease(string $ipAddress): ?DhcpLease;
}
