<?php

declare(strict_types=1);

namespace App\Services\Kea;

use App\Services\Null\NullDhcpService;
use App\Services\ValueObjects\DhcpLease;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class KeaDhcpService extends NullDhcpService
{
    private const PAGE_LIMIT = 1000;

    private const LEASE_STATE_ACTIVE = 0;

    /**
     * Kea's documented keyword for the first `lease4-get-page` request.
     */
    private const FIRST_PAGE_CURSOR = 'start';

    /**
     * Safety cap on pages fetched per sync, in case Kea's `from` cursor
     * never advances (misbehaving API) — prevents an infinite loop.
     */
    private const MAX_PAGES = 10_000;

    public function __construct(private readonly KeaClient $client) {}

    /** @return Collection<int, DhcpLease> */
    public function getLeases(): Collection
    {
        $leases = collect();
        $cursor = self::FIRST_PAGE_CURSOR;
        $now = Carbon::now()->getTimestamp();

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $entry = $this->client->sendCommand('lease4-get-page', [
                'from' => $cursor,
                'limit' => self::PAGE_LIMIT,
            ]);

            $arguments = $entry['arguments'] ?? null;

            if (! is_array($arguments)) {
                break;
            }

            /** @var mixed $pageLeases */
            $pageLeases = $arguments['leases'] ?? [];

            if (! is_array($pageLeases) || $pageLeases === []) {
                break;
            }

            $lastIp = $this->processPage($pageLeases, $leases, $now);

            if ($lastIp === null || $lastIp === $cursor) {
                break;
            }

            $cursor = $lastIp;
        }

        return $leases;
    }

    /**
     * @param  array<int|string, mixed>  $pageLeases
     * @param  Collection<int, DhcpLease>  $leases
     */
    private function processPage(array $pageLeases, Collection $leases, int $now): ?string
    {
        $lastIp = null;

        foreach ($pageLeases as $lease) {
            if (! is_array($lease)) {
                continue;
            }

            $ip = $lease['ip-address'] ?? null;

            if (! is_string($ip) || $ip === '') {
                continue;
            }

            $lastIp = $ip;

            if (! $this->isKeepable($lease, $now)) {
                continue;
            }

            $leases->push($this->mapLease($lease, $ip));
        }

        return $lastIp;
    }

    /**
     * @param  array<string, mixed>  $lease
     */
    private function isKeepable(array $lease, int $now): bool
    {
        $state = (int) ($lease['state'] ?? -1);

        if ($state !== self::LEASE_STATE_ACTIVE) {
            return false;
        }

        return $this->expiresAt($lease) > $now;
    }

    /**
     * @param  array<string, mixed>  $lease
     */
    private function expiresAt(array $lease): int
    {
        return (int) ($lease['cltt'] ?? 0) + (int) ($lease['valid-lft'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $lease
     */
    private function mapLease(array $lease, string $ip): DhcpLease
    {
        $mac = $lease['hw-address'] ?? null;
        $hostname = $lease['hostname'] ?? null;

        return new DhcpLease(
            ip: $ip,
            mac: is_string($mac) && $mac !== '' ? $mac : null,
            hostname: is_string($hostname) ? $hostname : '',
            expires: Carbon::createFromTimestamp($this->expiresAt($lease))->toIso8601String(),
        );
    }
}
