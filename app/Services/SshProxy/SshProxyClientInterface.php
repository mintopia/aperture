<?php

declare(strict_types=1);

namespace App\Services\SshProxy;

interface SshProxyClientInterface
{
    /**
     * @param  array<int, array{command: string, if?: string, expect?: string}>  $commands
     * @return array{success: bool, output: array<int, array{command: string, output: string}>, error?: string}
     */
    public function execute(string $hostname, string $username, string $password, array $commands): array;

    /**
     * @return array{uptime_seconds: int, connections: array<int, array{hostname: string, connected_seconds: int, last_used_seconds_ago: int, locked: bool}>}
     */
    public function status(): array;
}
