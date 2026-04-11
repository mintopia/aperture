<?php

namespace App\Services;

use App\Services\Interfaces\NetworkInventoryInterface;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;

class LibreNmsService implements NetworkInventoryInterface
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

    /**
     * @return Collection<int, array{mac: string, port: string, vlan: int}>
     */
    public function getForwardingDatabase(): Collection
    {
        $response = $this->client->get('/api/v0/resources/fdb');
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true);

        /** @var array<int, array{mac: string, port: string, vlan: int}> $mapped */
        $mapped = array_map(fn (array $entry): array => [
            'mac' => (string) ($entry['mac_address'] ?? ''),
            'port' => (string) ($entry['port_id'] ?? ''),
            'vlan' => (int) ($entry['vlan_id'] ?? 0),
        ], $data['fdb'] ?? []);

        return collect($mapped);
    }

    /**
     * @return Collection<int, array{ip: string, mac: string}>
     */
    public function getArpTable(): Collection
    {
        $response = $this->client->get('/api/v0/resources/ip/arp');
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true);

        /** @var array<int, array{ip: string, mac: string}> $mapped */
        $mapped = array_map(fn (array $entry): array => [
            'ip' => (string) ($entry['ipv4_address'] ?? ''),
            'mac' => (string) ($entry['mac_address'] ?? ''),
        ], $data['arp'] ?? []);

        return collect($mapped);
    }

    public function resolveIpToPort(string $ipAddress): ?array
    {
        $arp = $this->getArpTable()->firstWhere('ip', $ipAddress);
        if (! $arp) {
            return null;
        }

        $fdb = $this->getForwardingDatabase()->firstWhere('mac', $arp['mac']);
        if (! $fdb) {
            return null;
        }

        return [
            'ip' => $ipAddress,
            'mac' => $arp['mac'],
            'port' => $fdb['port'],
            'switch' => '',
        ];
    }

    /**
     * @return Collection<int, array{hostname: string, ip: string, type: string}>
     */
    public function getDeviceList(): Collection
    {
        $response = $this->client->get('/api/v0/devices');
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true);

        /** @var array<int, array{hostname: string, ip: string, type: string}> $mapped */
        $mapped = array_map(fn (array $device): array => [
            'hostname' => (string) ($device['hostname'] ?? ''),
            'ip' => (string) ($device['ip'] ?? ''),
            'type' => (string) ($device['type'] ?? ''),
        ], $data['devices'] ?? []);

        return collect($mapped);
    }
}
