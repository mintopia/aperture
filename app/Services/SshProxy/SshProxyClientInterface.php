<?php

declare(strict_types=1);

namespace App\Services\SshProxy;

interface SshProxyClientInterface
{
    /**
     * @param  array<int, array{command: string, if?: string, expect?: string}>  $commands
     */
    public function execute(string $hostname, string $username, string $password, array $commands, int $port = 22): CommandResult;

    public function status(): ProxyStatus;
}
