<?php

declare(strict_types=1);

namespace App\Services\VyOs;

use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\ValueObjects\ArpEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class VyOsIpMacResolver implements IpMacResolverInterface
{
    public function __construct(
        private VyOsClient $client,
    ) {}

    /** @return Collection<int, ArpEntry> */
    public function getArpTable(): Collection
    {
        $ipv4 = $this->fetchIpv4Neighbors();
        $ipv6 = $this->fetchIpv6Neighbors();

        return $ipv4->concat($ipv6)
            ->unique(fn (ArpEntry $entry): string => $entry->ip.'|'.$entry->mac)
            ->values();
    }

    /** @return Collection<int, ArpEntry> */
    private function fetchIpv4Neighbors(): Collection
    {
        try {
            $data = $this->client->show(['ip', 'neighbors']);

            return collect($data)->map(fn (array $entry): ArpEntry => new ArpEntry(
                ip: (string) ($entry['ip'] ?? ''),
                mac: (string) ($entry['mac'] ?? ''),
            ))->values();
        } catch (Throwable $throwable) {
            Log::warning('Failed to fetch VyOS IPv4 neighbors', ['error' => $throwable->getMessage()]);

            return collect();
        }
    }

    /** @return Collection<int, ArpEntry> */
    private function fetchIpv6Neighbors(): Collection
    {
        try {
            $data = $this->client->show(['ipv6', 'neighbors']);

            return collect($data)->map(fn (array $entry): ArpEntry => new ArpEntry(
                ip: (string) ($entry['ip'] ?? ''),
                mac: (string) ($entry['mac'] ?? ''),
            ))->values();
        } catch (Throwable $throwable) {
            Log::warning('Failed to fetch VyOS IPv6 neighbors', ['error' => $throwable->getMessage()]);

            return collect();
        }
    }
}
