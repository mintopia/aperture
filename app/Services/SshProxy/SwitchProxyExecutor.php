<?php

declare(strict_types=1);

namespace App\Services\SshProxy;

use App\Models\SwitchConfig;
use App\Services\Interfaces\SshProxyClientInterface;

class SwitchProxyExecutor
{
    public function __construct(private readonly SshProxyClientInterface $client) {}

    /**
     * @param  array<int, array{command: string, sensitive?: true, if?: array{type: 'literal'|'regex', value: string}, expect?: array{type: 'literal'|'regex', value: string}}>  $commands
     */
    public function execute(SwitchConfig $switchConfig, array $commands, string $channel = 'commands'): CommandResult
    {
        $result = $this->client->execute(
            $switchConfig->hostname,
            $switchConfig->username,
            $switchConfig->password ?? '',
            $commands,
            $switchConfig->port ?? 22,
            $channel,
            $switchConfig->private_key,
            $switchConfig->passphrase,
            $switchConfig->host_key,
        );

        if ($result->success && $result->hostKey !== null && blank($switchConfig->host_key) && $switchConfig->exists) {
            SwitchConfig::whereKey($switchConfig->getKey())->whereNull('host_key')->update(['host_key' => $result->hostKey]);
            $switchConfig->refresh();
        }

        return $result;
    }
}
