<?php

declare(strict_types=1);

namespace App\Services\Null;

use App\Services\Interfaces\HostStatsProviderInterface;
use App\Services\ValueObjects\HostBytes;

class NullHostStatsProvider implements HostStatsProviderInterface
{
    public function getHostBytes(string $ipAddress): ?HostBytes
    {
        return null;
    }
}
