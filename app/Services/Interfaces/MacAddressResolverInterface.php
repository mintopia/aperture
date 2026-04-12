<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

interface MacAddressResolverInterface
{
    public function resolveIpToMac(string $ipAddress): ?string;
}
