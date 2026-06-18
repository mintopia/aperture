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
            $text = $this->client->showText(['ip', 'neighbors']);

            return $this->parseNeighborText($text);
        } catch (Throwable $throwable) {
            Log::warning('Failed to fetch VyOS IPv4 neighbors', ['error' => $throwable->getMessage()]);

            return collect();
        }
    }

    /** @return Collection<int, ArpEntry> */
    private function fetchIpv6Neighbors(): Collection
    {
        try {
            $text = $this->client->showText(['ipv6', 'neighbors']);

            return $this->parseNeighborText($text);
        } catch (Throwable $throwable) {
            Log::warning('Failed to fetch VyOS IPv6 neighbors', ['error' => $throwable->getMessage()]);

            return collect();
        }
    }

    /** @return Collection<int, ArpEntry> */
    private function parseNeighborText(string $text): Collection
    {
        $entries = collect();

        foreach (explode("\n", $text) as $line) {
            $line = trim($line);

            if ($line === '' || preg_match('/^[-\s]+$/', $line) || preg_match('/^Address\b/i', $line)) {
                continue;
            }

            // VyOS tabular: Address  Interface  Link-layer-address  State
            if (preg_match('/^(\S+)\s+\S+\s+([\da-f]{2}(?::[\da-f]{2}){5})\s+/i', $line, $matches)) {
                $entries->push(new ArpEntry(
                    ip: $matches[1],
                    mac: strtolower($matches[2]),
                ));
            }
        }

        return $entries;
    }
}
