<?php

declare(strict_types=1);

namespace App\Services\LibreNms;

use App\Services\ValueObjects\ArpEntry;
use App\Services\ValueObjects\ForwardingEntry;
use App\Services\ValueObjects\NetworkDevice;
use App\Services\ValueObjects\PortDetail;
use App\Services\ValueObjects\ResolvedPort;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;

class LibreNmsService
{
    protected Client $client;

    public function __construct(string $endpoint, string $apiToken)
    {
        $this->client = new Client([
            'base_uri' => $endpoint,
            'headers' => [
                'X-Auth-Token' => $apiToken,
                'Accept' => 'application/json',
            ],
        ]);
    }

    /** @return Collection<int, ForwardingEntry> */
    public function getForwardingDatabase(): Collection
    {
        $response = $this->client->get('/api/v0/resources/fdb');
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true);

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
        $response = $this->client->get('/api/v0/resources/ip/arp');
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true);

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

    /** @return Collection<int, NetworkDevice> */
    public function getDeviceList(): Collection
    {
        $response = $this->client->get('/api/v0/devices');
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true);

        return collect(array_map(
            fn (array $device): NetworkDevice => new NetworkDevice(
                hostname: (string) ($device['hostname'] ?? ''),
                ip: (string) ($device['ip'] ?? ''),
                type: (string) ($device['type'] ?? ''),
            ),
            $data['devices'] ?? [],
        ));
    }

    public function getPortDetail(string $portId): ?PortDetail
    {
        $response = $this->client->get('/api/v0/ports/'.$portId);
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true);

        $port = $data['port'] ?? null;
        if ($port === null) {
            return null;
        }

        $deviceId = (string) ($port['device_id'] ?? '');
        $deviceResponse = $this->client->get('/api/v0/devices/'.$deviceId);
        /** @var array<string, mixed> $deviceData */
        $deviceData = json_decode((string) $deviceResponse->getBody(), true);

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
        $response = $this->client->get('/api/v0/resources/ip/arp');
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true);

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
