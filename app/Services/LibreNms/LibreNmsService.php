<?php

declare(strict_types=1);

namespace App\Services\LibreNms;

use App\Services\Http\ExternalHttp;
use App\Services\ValueObjects\ForwardingEntry;
use App\Services\ValueObjects\IpMacEntry;
use App\Services\ValueObjects\PortDetail;
use App\Services\ValueObjects\ResolvedPort;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;

class LibreNmsService
{
    public function __construct(
        protected string $endpoint,
        protected string $apiToken,
        protected bool $verifySsl = true,
    ) {}

    protected function http(): PendingRequest
    {
        return ExternalHttp::request($this->endpoint, $this->verifySsl)
            ->withHeaders(['X-Auth-Token' => $this->apiToken])
            ->acceptJson()
            ->throw();
    }

    /** @return Collection<int, ForwardingEntry> */
    public function getForwardingDatabase(): Collection
    {
        /** @var array<string, mixed> $data */
        $data = $this->http()->get('/api/v0/resources/fdb')->json();

        return collect(array_map(
            fn (array $entry): ForwardingEntry => new ForwardingEntry(
                mac: (string) ($entry['mac_address'] ?? ''),
                port: (string) ($entry['port_id'] ?? ''),
                vlan: (int) ($entry['vlan_id'] ?? 0),
            ),
            $data['fdb'] ?? [],
        ));
    }

    /** @return Collection<int, IpMacEntry> */
    public function getIpMacTable(): Collection
    {
        /** @var array<string, mixed> $data */
        $data = $this->http()->get('/api/v0/resources/ip/arp')->json();

        return collect(array_map(
            fn (array $entry): IpMacEntry => new IpMacEntry(
                ip: (string) ($entry['ipv4_address'] ?? ''),
                mac: (string) ($entry['mac_address'] ?? ''),
            ),
            $data['arp'] ?? [],
        ));
    }

    public function resolveIpToPort(string $ipAddress): ?ResolvedPort
    {
        $arp = $this->getIpMacTable()->firstWhere('ip', $ipAddress);
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
        /** @var array<string, mixed> $data */
        $data = $this->http()->get('/api/v0/ports/'.$portId)->json();

        $port = $data['port'] ?? null;
        if ($port === null) {
            return null;
        }

        $deviceId = (string) ($port['device_id'] ?? '');
        /** @var array<string, mixed> $deviceData */
        $deviceData = $this->http()->get('/api/v0/devices/'.$deviceId)->json();

        return new PortDetail(
            hostname: (string) ($deviceData['devices'][0]['hostname'] ?? ''),
            interface: (string) ($port['ifName'] ?? ''),
            status: (string) ($port['ifOperStatus'] ?? ''),
            adminStatus: (string) ($port['ifAdminStatus'] ?? ''),
            speed: (int) ($port['ifSpeed'] ?? 0),
        );
    }

    /** @return Collection<int, IpMacEntry> */
    public function getIpv6Neighbors(): Collection
    {
        /** @var array<string, mixed> $data */
        $data = $this->http()->get('/api/v0/resources/ip/arp')->json();

        $entries = array_map(
            fn (array $entry): IpMacEntry => new IpMacEntry(
                ip: (string) ($entry['ipv4_address'] ?? ''),
                mac: (string) ($entry['mac_address'] ?? ''),
            ),
            $data['arp'] ?? [],
        );

        return collect(array_values(array_filter(
            $entries,
            fn (IpMacEntry $entry): bool => str_contains($entry->ip, ':'),
        )));
    }
}
