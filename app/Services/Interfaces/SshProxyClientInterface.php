<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\SshProxy\CommandResult;

interface SshProxyClientInterface
{
    /**
     * @param  array<int, array{command: string, if?: string, expect?: string}>  $commands
     */
    public function execute(string $hostname, string $username, string $password, array $commands, int $port = 22, string $channel = 'commands', ?string $privateKey = null, ?string $passphrase = null, ?string $hostKey = null): CommandResult;
}
