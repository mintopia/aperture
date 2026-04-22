<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use App\Models\SwitchConfig;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\Interfaces\SshProxyClientInterface;
use App\Services\Interfaces\SwitchCommandTransportInterface;
use App\Services\NetworkSwitch\Transport\DirectSshTransport;
use App\Services\NetworkSwitch\Transport\SshProxyTransport;
use InvalidArgumentException;
use RuntimeException;

class SwitchServiceFactory
{
    public function __construct(
        private ?SshProxyClientInterface $proxyClient,
        private bool $proxyEnabled,
    ) {}

    public function make(SwitchConfig $switchConfig): NetworkSwitchInterface
    {
        return match ($switchConfig->type) {
            'cisco' => new CiscoSwitchAdapter($this->createTransport($switchConfig), new IosOutputParser),
            default => throw new InvalidArgumentException(sprintf('Unsupported switch type [%s].', $switchConfig->type)),
        };
    }

    private function createTransport(SwitchConfig $switchConfig): SwitchCommandTransportInterface
    {
        if ($this->proxyEnabled) {
            if (! $this->proxyClient instanceof SshProxyClientInterface) {
                throw new RuntimeException('SSH proxy is enabled but no proxy client is available.');
            }

            return new SshProxyTransport($this->proxyClient, $switchConfig);
        }

        return new DirectSshTransport(
            hostname: $switchConfig->hostname,
            username: $switchConfig->username,
            password: $switchConfig->password,
            enablePassword: $switchConfig->enable_password ?? '',
            port: $switchConfig->port ?? 22,
            timeout: $switchConfig->timeout ?? 30,
        );
    }
}
