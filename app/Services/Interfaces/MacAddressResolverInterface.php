<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

interface MacAddressResolverInterface
{
    public function resolveIpToMac(string $ipAddress): ?string;

    /** @return array<int, array{ip: string, hostname: string}> */
    public function resolveMacToIps(string $macAddress): array;
}
