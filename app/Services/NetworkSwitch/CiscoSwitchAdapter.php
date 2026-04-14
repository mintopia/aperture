<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use App\Services\CiscoService;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\ValueObjects\ForwardingEntry;
use App\Services\ValueObjects\PortStatistics;
use App\Services\ValueObjects\PortStatus;
use Illuminate\Support\Collection;

class CiscoSwitchAdapter implements NetworkSwitchInterface
{
    public function __construct(
        protected CiscoService $ciscoService,
        protected IosOutputParser $parser,
    ) {}

    public function getPortStatus(string $portId): PortStatus
    {
        $output = $this->ciscoService->showInterface($portId);

        return $this->parser->parseShowInterface($output);
    }

    /** @return Collection<int, PortStatus> */
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

    public function getPortStatistics(string $portId): PortStatistics
    {
        $output = $this->ciscoService->showInterface($portId);

        return $this->parser->parseInterfaceCounters($output);
    }

    /** @return Collection<int, ForwardingEntry> */
    public function getForwardingDatabase(): Collection
    {
        $output = $this->ciscoService->showMacAddressTable();

        return collect($this->parser->parseMacAddressTable($output));
    }
}
