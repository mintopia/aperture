<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use App\Models\SwitchConfig;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\Interfaces\SshProxyClientInterface;
use App\Services\Interfaces\SwitchCommandTransportInterface;
use App\Services\NetworkSwitch\Transport\SshProxyTransport;
use InvalidArgumentException;
use RuntimeException;

class SwitchServiceFactory
{
    public function __construct(
        private ?SshProxyClientInterface $proxyClient,
    ) {}

    public function make(SwitchConfig $switchConfig): NetworkSwitchInterface
    {
        return match ($switchConfig->type) {
            'cisco' => new CiscoSwitchAdapter($this->createTransport($switchConfig), new IosOutputParser),
            default => throw new InvalidArgumentException(sprintf('Unsupported switch type [%s].', $switchConfig->type)),
        };
    }

    public function createTransport(SwitchConfig $switchConfig): SwitchCommandTransportInterface
    {
        if (! $this->proxyClient instanceof SshProxyClientInterface) {
            throw new RuntimeException('SSH proxy client is not available. The SSH proxy is the only supported switch transport; configure aperture.ssh_proxy and run the ssh-proxy sidecar.');
        }

        return new SshProxyTransport($this->proxyClient, $switchConfig);
    }
}
