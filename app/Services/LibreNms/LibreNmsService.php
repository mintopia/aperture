<?php

declare(strict_types=1);

namespace App\Services\LibreNms;

use App\Services\ValueObjects\ArpEntry;
use App\Services\ValueObjects\ForwardingEntry;
use App\Services\ValueObjects\PortDetail;
use App\Services\ValueObjects\ResolvedPort;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class LibreNmsService
{
    public function __construct(protected string $endpoint, protected string $apiToken) {}

    protected function request(): PendingRequest
    {
        return Http::baseUrl($this->endpoint)
            ->withHeaders(['X-Auth-Token' => $this->apiToken])
            ->acceptJson();
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetch(string $path): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->request()->get($path)->throw()->json() ?? [];

        return $data;
    }

    /** @return Collection<int, ForwardingEntry> */
    public function getForwardingDatabase(): Collection
    {
        $data = $this->fetch('/api/v0/resources/fdb');

        return collect(array_map(
            fn (array $entry): ForwardingEntry => new ForwardingEntry(
                mac: (string) ($entry['mac_address'] ?? ''),
                port: (string) ($entry['port_id'] ?? ''),
                vlan: (int) ($entry['vlan_id'] ?? 0),
            ),
            $data['fdb'] ?? [],
        ));
    }

    /** @return Collection<int, ArpEntry> */
    public function getArpTable(): Collection
    {
        $data = $this->fetch('/api/v0/resources/ip/arp');

        return collect(array_map(
            fn (array $entry): ArpEntry => new ArpEntry(
                ip: (string) ($entry['ipv4_address'] ?? ''),
                mac: (string) ($entry['mac_address'] ?? ''),
            ),
            $data['arp'] ?? [],
        ));
    }

    public function resolveIpToPort(string $ipAddress): ?ResolvedPort
    {
        $arp = $this->getArpTable()->firstWhere('ip', $ipAddress);
        if (! $arp) {
            return null;
        }

        $fdb = $this->getForwardingDatabase()->firstWhere('mac', $arp->mac);
        if (! $fdb) {
            return null;
        }

        return new ResolvedPort(
            ip: $ipAddress,
            mac: $arp->mac,
            port: $fdb->port,
            switch: '',
        );
    }

    public function getPortDetail(string $portId): ?PortDetail
    {
        $data = $this->fetch('/api/v0/ports/'.$portId);

        $port = $data['port'] ?? null;
        if ($port === null) {
            return null;
        }

        $deviceId = (string) ($port['device_id'] ?? '');
        $deviceData = $this->fetch('/api/v0/devices/'.$deviceId);

        return new PortDetail(
            hostname: (string) ($deviceData['devices'][0]['hostname'] ?? ''),
            interface: (string) ($port['ifName'] ?? ''),
            status: (string) ($port['ifOperStatus'] ?? ''),
            adminStatus: (string) ($port['ifAdminStatus'] ?? ''),
            speed: (int) ($port['ifSpeed'] ?? 0),
        );
    }

    /** @return Collection<int, ArpEntry> */
    public function getIpv6Neighbors(): Collection
    {
        $data = $this->fetch('/api/v0/resources/ip/arp');

        $entries = array_map(
            fn (array $entry): ArpEntry => new ArpEntry(
                ip: (string) ($entry['ipv4_address'] ?? ''),
                mac: (string) ($entry['mac_address'] ?? ''),
            ),
            $data['arp'] ?? [],
        );

        return collect(array_values(array_filter(
            $entries,
            fn (ArpEntry $entry): bool => str_contains($entry->ip, ':'),
        )));
    }
}
