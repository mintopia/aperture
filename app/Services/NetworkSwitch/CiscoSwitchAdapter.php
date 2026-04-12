<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use App\Services\CiscoService;
use App\Services\Interfaces\NetworkSwitchInterface;
use Illuminate\Support\Collection;

class CiscoSwitchAdapter implements NetworkSwitchInterface
{
    public function __construct(
        protected CiscoService $ciscoService,
        protected IosOutputParser $parser,
    ) {}

    /**
     * @return array{interface: string, status: string, speed: string, duplex: string, vlan: string}
     */
    public function getPortStatus(string $portId): array
    {
        $output = $this->ciscoService->showInterface($portId);

        return $this->parser->parseShowInterface($output);
    }

    /**
     * @return Collection<int, array{interface: string, status: string, speed: string, vlan: string}>
     */
    public function getAllPorts(): Collection
    {
        $output = $this->ciscoService->showInterfaceStatus();

        return collect($this->parser->parseInterfaceStatusTable($output));
    }

    public function shutdownPort(string $portId): bool
    {
        $this->ciscoService->shutInterface($portId);

        return true;
    }

    public function enablePort(string $portId): bool
    {
        $this->ciscoService->unshutInterface($portId);

        return true;
    }

    /**
     * @return array{in_bytes: int, out_bytes: int, in_errors: int, out_errors: int}
     */
    public function getPortStatistics(string $portId): array
    {
        $output = $this->ciscoService->showInterface($portId);

        return $this->parser->parseInterfaceCounters($output);
    }

    /**
     * @return Collection<int, array{mac: string, port: string, vlan: int}>
     */
    public function getForwardingDatabase(): Collection
    {
        $output = $this->ciscoService->showMacAddressTable();

        return collect($this->parser->parseMacAddressTable($output));
    }
}
