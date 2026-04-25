<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\ValueObjects\HostBytes;

interface HostStatsProviderInterface
{
    public function getHostBytes(string $ipAddress): ?HostBytes;
}
