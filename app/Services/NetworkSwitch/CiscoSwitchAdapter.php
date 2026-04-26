<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use App\Exceptions\InvalidPortIdentifierException;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\Interfaces\SupportsBulkOperations;
use App\Services\Interfaces\SupportsInterfaceOutputCapture;
use App\Services\Interfaces\SwitchCommandTransportInterface;
use App\Services\ValueObjects\ForwardingEntry;
use App\Services\ValueObjects\PortStatistics;
use App\Services\ValueObjects\PortStatus;
use Illuminate\Support\Collection;

class CiscoSwitchAdapter implements NetworkSwitchInterface, SupportsBulkOperations, SupportsInterfaceOutputCapture
{
    /**
     * Regex matching valid Cisco IOS interface identifiers.
     *
     * Covers: GigabitEthernet, FastEthernet, TenGigabitEthernet,
     * TwentyFiveGigE, FortyGigabitEthernet, HundredGigE,
     * Port-channel, Vlan, Loopback, Tunnel, Mgmt, nve, Ethernet
     * and their abbreviated forms (Gi, Fa, Te, Twe, Fo, Hu, Po, Vl, Lo, Tu, Eth).
     */
    private const string PORT_ID_PATTERN = '/^[A-Za-z][A-Za-z0-9-]*\d+(\\/\d+){0,3}$/';

    /** @var array<string, string>|null */
    private ?array $bulkInterfaceCache = null;

    /** @var array<string, string>|null */
    private ?array $bulkConfigCache = null;

    public function __construct(
        protected SwitchCommandTransportInterface $transport,
        protected IosOutputParser $parser,
    ) {}

    /**
     * Validate a port identifier against the Cisco IOS interface naming pattern.
     *
     * @throws InvalidPortIdentifierException
     */
    private function validatePortIdentifier(string $portId): void
    {
        if (preg_match(self::PORT_ID_PATTERN, $portId) !== 1) {
            throw InvalidPortIdentifierException::forPortId($portId);
        }
    }

    public function getPortStatus(string $portId): PortStatus
    {
        $this->validatePortIdentifier($portId);
        $output = $this->getAllPortInterfaceOutputs()[$portId] ?? $this->getPortInterfaceOutput($portId);
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
        $this->validatePortIdentifier($portId);

        return $this->getAllPortInterfaceOutputs()[$portId]
            ?? $this->transport->execute('show interface '.$portId);
    }

    /**
     * @return array<string, string>
     */
    public function getAllPortInterfaceOutputs(): array
    {
        if ($this->bulkInterfaceCache === null) {
            $output = $this->transport->execute('show interface');
            $this->bulkInterfaceCache = $this->indexByBothNameForms(
                $this->parser->splitBulkShowInterface($output),
            );
        }

        return $this->bulkInterfaceCache;
    }

    /**
     * @return array<string, string>
     */
    public function getAllPortRunningConfigs(): array
    {
        if ($this->bulkConfigCache === null) {
            $output = $this->transport->execute('show running-config | section ^interface');
            $this->bulkConfigCache = $this->indexByBothNameForms(
                $this->parser->splitBulkRunningConfig($output),
            );
        }

        return $this->bulkConfigCache;
    }

    /**
     * Index an array of interface data by both full and abbreviated name forms.
     *
     * Ensures lookups work regardless of whether a full name (GigabitEthernet1/0/1)
     * or abbreviated name (Gi1/0/1) is used as the key.
     *
     * @param  array<string, string>  $data
     * @return array<string, string>
     */
    private function indexByBothNameForms(array $data): array
    {
        $result = $data;

        foreach ($data as $name => $value) {
            $abbreviated = $this->parser->abbreviateInterfaceName($name);
            if ($abbreviated !== $name) {
                $result[$abbreviated] = $value;
            }
        }

        return $result;
    }

    /** @return Collection<int, PortStatus> */
    public function getAllPorts(): Collection
    {
        $output = $this->transport->execute('show interface status');

        return collect($this->parser->parseInterfaceStatusTable($output));
    }

    public function shutdownPort(string $portId): bool
    {
        $this->validatePortIdentifier($portId);
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
        $this->validatePortIdentifier($portId);
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
        $this->validatePortIdentifier($portId);
        $output = $this->getAllPortInterfaceOutputs()[$portId]
            ?? $this->transport->execute('show interface '.$portId);

        return $this->parser->parseInterfaceCounters($output);
    }

    public function getRunningConfig(): string
    {
        return $this->transport->execute('show running-config');
    }

    public function getPortRunningConfig(string $portId): string
    {
        $this->validatePortIdentifier($portId);

        return $this->getAllPortRunningConfigs()[$portId]
            ?? $this->transport->execute('show run interface '.$portId);
    }

    /** @return Collection<int, ForwardingEntry> */
    public function getForwardingDatabase(): Collection
    {
        $output = $this->transport->execute('show mac address-table');

        return collect($this->parser->parseMacAddressTable($output));
    }
}
