<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\Interfaces\SupportsInterfaceOutputCapture;
use App\Services\Interfaces\SwitchCommandTransportInterface;
use App\Services\ValueObjects\ForwardingEntry;
use App\Services\ValueObjects\PortStatistics;
use App\Services\ValueObjects\PortStatus;
use Illuminate\Support\Collection;

class CiscoSwitchAdapter implements NetworkSwitchInterface, SupportsInterfaceOutputCapture
{
    public function __construct(
        protected SwitchCommandTransportInterface $transport,
        protected IosOutputParser $parser,
    ) {}

    public function getPortStatus(string $portId): PortStatus
    {
        $output = $this->getPortInterfaceOutput($portId);
        $parsed = $this->parser->parseShowInterface($output);

        return new PortStatus(
            interface: $parsed->interface,
            status: $parsed->status,
            speed: $parsed->speed,
            duplex: $parsed->duplex,
            vlan: $parsed->vlan,
            description: $output,
            switchportMode: $parsed->switchportMode,
        );
    }

    public function getPortInterfaceOutput(string $portId): string
    {
        return $this->transport->execute('show interface '.$portId);
    }

    /** @return Collection<int, PortStatus> */
    public function getAllPorts(): Collection
    {
        $output = $this->transport->execute('show interface status');

        return collect($this->parser->parseInterfaceStatusTable($output));
    }

    public function shutdownPort(string $portId): bool
    {
        $this->transport->executeMultiple([
            'configure terminal',
            'interface '.$portId,
            'shutdown',
            'end',
            'write memory',
        ]);

        return true;
    }

    public function enablePort(string $portId): bool
    {
        $this->transport->executeMultiple([
            'configure terminal',
            'interface '.$portId,
            'no shutdown',
            'end',
            'write memory',
        ]);

        return true;
    }

    public function getPortStatistics(string $portId): PortStatistics
    {
        $output = $this->transport->execute('show interface '.$portId);

        return $this->parser->parseInterfaceCounters($output);
    }

    public function getRunningConfig(): string
    {
        return $this->transport->execute('show running-config');
    }

    public function getPortRunningConfig(string $portId): string
    {
        return $this->transport->execute('show run interface '.$portId);
    }

    /** @return Collection<int, ForwardingEntry> */
    public function getForwardingDatabase(): Collection
    {
        $output = $this->transport->execute('show mac address-table');

        return collect($this->parser->parseMacAddressTable($output));
    }
}
