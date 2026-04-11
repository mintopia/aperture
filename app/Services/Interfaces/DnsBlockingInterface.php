<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

interface DnsBlockingInterface
{
    public function isEnabledForIp(string $ipAddress): bool;

    public function enableForIp(string $ipAddress): void;

    public function disableForIp(string $ipAddress): void;
}
