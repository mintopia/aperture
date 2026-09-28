<?php

declare(strict_types=1);

namespace App\Services\Kea;

use App\Services\Interfaces\DhcpInterface;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpPoolStatus;
use App\Services\ValueObjects\DhcpRange;
use App\Support\Duid;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class KeaDhcpService implements DhcpInterface
{
    private const PAGE_LIMIT = 1000;

    private const LEASE_STATE_ACTIVE = 0;

    /**
     * Kea's documented keyword for the first `lease{4,6}-get-page` request.
     */
    private const FIRST_PAGE_CURSOR = 'start';

    /**
     * Safety cap on pages fetched per sync, in case Kea's `from` cursor
     * never advances (misbehaving API) — prevents an infinite loop.
     */
    private const MAX_PAGES = 10_000;

    /**
     * @var array{ipv4: Collection<int, DhcpLease>, ipv6: Collection<int, DhcpLease>, ranges: Collection<int, DhcpRange>|null}|null
     */
    private ?array $snapshot = null;

    /** @var array{ipv4: bool, ipv6: bool} */
    private array $fetchStatus = ['ipv4' => false, 'ipv6' => false];

    public function __construct(
        private readonly ?KeaClient $ipv4Client,
        private readonly ?KeaClient $ipv6Client = null,
    ) {}

    public function getPoolStatus(): DhcpPoolStatus
    {
        $ranges = $this->getRanges();

        $total = 0;
        $used = 0;

        foreach ($ranges as $range) {
            $total += (int) ($range->totalAddresses ?? '0');
            $used += $range->usedAddresses ?? 0;
        }

        return new DhcpPoolStatus(
            total: $total,
            used: $used,
            available: max(0, $total - $used),
            utilisation: $total > 0 ? round($used / $total, 4) : 0.0,
        );
    }

    /** @return Collection<int, DhcpRange> */
    public function getRanges(): Collection
    {
        $this->ensureSnapshot();

        $client = $this->usableIpv4RangeClient();

        if ($client === null) {
            return collect();
        }

        /** @var Collection<int, DhcpRange>|null $cached */
        $cached = $this->snapshot['ranges'] ?? null;

        if ($cached !== null) {
            return $cached;
        }

        try {
            $entry = $client->sendCommand('config-get');
        } catch (Throwable $throwable) {
            Log::warning('Kea config-get failed', ['error' => $throwable->getMessage()]);
            $this->fetchStatus['ipv4'] = false;

            return $this->cacheRanges(collect());
        }

        $arguments = $entry['arguments'] ?? null;

        if (! is_array($arguments)) {
            return $this->cacheRanges(collect());
        }

        $dhcp4 = $arguments['Dhcp4'] ?? null;

        if (! is_array($dhcp4)) {
            return $this->cacheRanges(collect());
        }

        $ranges = collect();

        $this->collectSubnets($ranges, $dhcp4['subnet4'] ?? [], '');

        $sharedNetworks = $dhcp4['shared-networks'] ?? [];

        if (is_array($sharedNetworks)) {
            foreach ($sharedNetworks as $sharedNetwork) {
                if (! is_array($sharedNetwork)) {
                    continue;
                }

                $name = $sharedNetwork['name'] ?? null;
                $fallbackInterface = is_string($name) && $name !== '' ? $name : '';

                $this->collectSubnets($ranges, $sharedNetwork['subnet4'] ?? [], $fallbackInterface);
            }
        }

        if ($ranges->isEmpty()) {
            return $this->cacheRanges($ranges);
        }

        /** @var Collection<int, DhcpLease> $leases */
        $leases = $this->snapshot['ipv4'] ?? collect();

        return $this->cacheRanges(
            $ranges->map(fn (DhcpRange $range): DhcpRange => $this->enrichRangeWithUsage($range, $leases))->values()
        );
    }

    private function usableIpv4RangeClient(): ?KeaClient
    {
        if (! $this->ipv4Client instanceof KeaClient || ! $this->fetchStatus['ipv4']) {
            return null;
        }

        return $this->ipv4Client;
    }

    /**
     * @param  Collection<int, DhcpRange>  $ranges
     * @return Collection<int, DhcpRange>
     */
    private function cacheRanges(Collection $ranges): Collection
    {
        if ($this->snapshot !== null) {
            $this->snapshot['ranges'] = $ranges;
        }

        return $ranges;
    }

    /** @return Collection<int, DhcpLease> */
    public function getLeases(): Collection
    {
        $this->refreshSnapshot();

        /** @var Collection<int, DhcpLease> $ipv4 */
        $ipv4 = $this->snapshot['ipv4'] ?? collect();
        /** @var Collection<int, DhcpLease> $ipv6 */
        $ipv6 = $this->snapshot['ipv6'] ?? collect();

        return $ipv4->concat($ipv6)->values();
    }

    public function getLease(string $ipAddress): ?DhcpLease
    {
        if (! $this->ipv4Client instanceof KeaClient) {
            return null;
        }

        if (! self::isIpv4Address($ipAddress)) {
            return null;
        }

        try {
            $entry = $this->ipv4Client->sendCommand('lease4-get', ['ip-address' => $ipAddress]);
        } catch (Throwable $throwable) {
            Log::warning('Kea lease4-get failed', ['ip' => $ipAddress, 'error' => $throwable->getMessage()]);

            return null;
        }

        $lease = $entry['arguments'] ?? null;

        if (! is_array($lease) || $lease === []) {
            return null;
        }

        if (($lease['state'] ?? null) !== self::LEASE_STATE_ACTIVE) {
            return null;
        }

        if (! is_int($lease['cltt'] ?? null) || ! is_int($lease['valid-lft'] ?? null)) {
            return null;
        }

        $expiresAt = $lease['cltt'] + $lease['valid-lft'];

        if ($expiresAt <= now()->getTimestamp()) {
            return null;
        }

        $hwAddress = $lease['hw-address'] ?? null;
        $hostname = $lease['hostname'] ?? null;

        return new DhcpLease(
            ip: $ipAddress,
            mac: is_string($hwAddress) && $hwAddress !== '' ? $hwAddress : null,
            hostname: is_string($hostname) ? $hostname : '',
            expires: Carbon::createFromTimestamp($expiresAt)->toIso8601String(),
        );
    }

    private static function isIpv4Address(string $ipAddress): bool
    {
        return filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    public function resetSnapshot(): void
    {
        $this->snapshot = null;
        $this->fetchStatus = ['ipv4' => false, 'ipv6' => false];
    }

    private function refreshSnapshot(): void
    {
        $this->resetSnapshot();
        $this->ensureSnapshot();
    }

    /**
     * @return array{ipv4: bool, ipv6: bool}
     */
    public function getFetchStatus(): array
    {
        $this->ensureSnapshot();

        return $this->fetchStatus;
    }

    private function ensureSnapshot(): void
    {
        if ($this->snapshot !== null) {
            return;
        }

        $this->fetchSnapshot();
    }

    private function fetchSnapshot(): void
    {
        $now = Carbon::now()->getTimestamp();

        /** @var Collection<int, DhcpLease> $ipv4Leases */
        $ipv4Leases = collect();
        /** @var Collection<int, DhcpLease> $ipv6Leases */
        $ipv6Leases = collect();

        if (! $this->ipv4Client instanceof KeaClient) {
            $this->markFamilyAsDeliberatelyUnconfigured('ipv4');
        } else {
            try {
                $ipv4Leases = $this->fetchFamilyLeases($this->ipv4Client, 'lease4-get-page', $now, isIpv6: false);
                $this->fetchStatus['ipv4'] = true;
            } catch (Throwable $e) {
                Log::warning('Kea IPv4 lease fetch failed', ['error' => $e->getMessage()]);
                $this->fetchStatus['ipv4'] = false;
            }
        }

        if (! $this->ipv6Client instanceof KeaClient) {
            $this->markFamilyAsDeliberatelyUnconfigured('ipv6');
        } else {
            try {
                $ipv6Leases = $this->fetchFamilyLeases($this->ipv6Client, 'lease6-get-page', $now, isIpv6: true);
                $this->fetchStatus['ipv6'] = true;
            } catch (Throwable $e) {
                Log::warning('Kea IPv6 lease fetch failed', ['error' => $e->getMessage()]);
                $this->fetchStatus['ipv6'] = false;
            }
        }

        $this->snapshot = [
            'ipv4' => $ipv4Leases,
            'ipv6' => $ipv6Leases,
            'ranges' => null,
        ];
    }

    /**
     * @param  'ipv4'|'ipv6'  $family
     */
    private function markFamilyAsDeliberatelyUnconfigured(string $family): void
    {
        $this->fetchStatus[$family] = true;
    }

    /**
     * @return Collection<int, DhcpLease>
     */
    private function fetchFamilyLeases(KeaClient $client, string $command, int $now, bool $isIpv6): Collection
    {
        $leases = collect();
        $cursor = self::FIRST_PAGE_CURSOR;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $entry = $client->sendCommand($command, [
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

            $lastIp = $this->processPage($pageLeases, $leases, $now, $isIpv6);

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
    private function processPage(array $pageLeases, Collection $leases, int $now, bool $isIpv6 = false): ?string
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

            // Kea's lease6-get-page response mixes address leases (IA_NA)
            // with prefix-delegation entries (IA_PD) in the same page. PD
            // entries are router-to-router delegations, not host leases, so
            // they're excluded here — but the pagination cursor above must
            // still advance past them.
            if ($isIpv6 && ($lease['type'] ?? null) === 'IA_PD') {
                continue;
            }

            if (! $this->isKeepable($lease, $now)) {
                continue;
            }

            $leases->push($this->mapLease($lease, $ip, $isIpv6));
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
    private function mapLease(array $lease, string $ip, bool $isIpv6 = false): DhcpLease
    {
        $mac = $lease['hw-address'] ?? null;
        $mac = is_string($mac) && $mac !== '' ? $mac : null;

        if ($mac === null && $isIpv6) {
            $duid = $lease['duid'] ?? null;

            if (is_string($duid) && $duid !== '') {
                $mac = Duid::macAddress($duid);
            }
        }

        $hostname = $lease['hostname'] ?? null;

        return new DhcpLease(
            ip: $ip,
            mac: $mac,
            hostname: is_string($hostname) ? $hostname : '',
            expires: Carbon::createFromTimestamp($this->expiresAt($lease))->toIso8601String(),
        );
    }

    /**
     * @param  Collection<int, DhcpRange>  $ranges
     */
    private function collectSubnets(Collection $ranges, mixed $subnets, string $fallbackInterface): void
    {
        if (! is_array($subnets)) {
            return;
        }

        foreach ($subnets as $subnet) {
            $this->collectSubnetRanges($ranges, $subnet, $fallbackInterface);
        }
    }

    /**
     * @param  Collection<int, DhcpRange>  $ranges
     */
    private function collectSubnetRanges(Collection $ranges, mixed $subnet, string $fallbackInterface): void
    {
        if (! is_array($subnet)) {
            return;
        }

        $cidr = $subnet['subnet'] ?? null;

        if (! is_string($cidr) || $cidr === '') {
            return;
        }

        $pools = $subnet['pools'] ?? [];

        if (! is_array($pools)) {
            return;
        }

        $interfaceField = $subnet['interface'] ?? null;
        $interface = is_string($interfaceField) && $interfaceField !== '' ? $interfaceField : $fallbackInterface;
        $subnetLabel = $this->contextName($subnet['user-context'] ?? null);

        foreach ($pools as $pool) {
            $range = $this->buildRange($pool, $cidr, $interface, $subnetLabel);

            if ($range instanceof DhcpRange) {
                $ranges->push($range);
            }
        }
    }

    private function buildRange(mixed $pool, string $cidr, string $interface, ?string $subnetLabel): ?DhcpRange
    {
        if (! is_array($pool)) {
            return null;
        }

        $poolString = $pool['pool'] ?? null;

        if (! is_string($poolString) || $poolString === '') {
            return null;
        }

        $bounds = $this->parseRangeBounds($poolString);

        if ($bounds === null) {
            return null;
        }

        [$from, $to] = $bounds;

        $label = $this->contextName($pool['user-context'] ?? null)
            ?? $subnetLabel
            ?? sprintf('%s (%s–%s)', $cidr, $from, $to);

        return new DhcpRange(
            interface: $interface,
            type: 'ipv4',
            subnet: $cidr,
            rangeFrom: $from,
            rangeTo: $to,
            prefix: null,
            gateway: null,
            description: $label,
        );
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function parseRangeBounds(string $pool): ?array
    {
        if (str_contains($pool, '/')) {
            return $this->parseCidrRangeBounds($pool);
        }

        if (preg_match('/^\s*([^\s-]+)\s*-\s*([^\s-]+)\s*$/', $pool, $matches) !== 1) {
            return null;
        }

        $fromLong = ip2long($matches[1]);
        $toLong = ip2long($matches[2]);

        if ($fromLong === false || $toLong === false || $fromLong > $toLong) {
            return null;
        }

        return [$matches[1], $matches[2]];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function parseCidrRangeBounds(string $cidr): ?array
    {
        [$network, $prefixLength] = array_pad(explode('/', $cidr, 2), 2, '');

        if (! ctype_digit($prefixLength)) {
            return null;
        }

        $prefixLength = (int) $prefixLength;

        if ($prefixLength > 32) {
            return null;
        }

        $networkLong = ip2long($network);

        if ($networkLong === false) {
            return null;
        }

        // Masking with 0xFFFFFFFF keeps the shift result within 32 bits on 64-bit PHP builds.
        $mask = $prefixLength === 0 ? 0 : ((-1 << (32 - $prefixLength)) & 0xFFFFFFFF);
        $networkLong &= $mask;

        return [long2ip($networkLong), long2ip($networkLong | (~$mask & 0xFFFFFFFF))];
    }

    private function contextName(mixed $userContext): ?string
    {
        if (! is_array($userContext)) {
            return null;
        }

        $name = $userContext['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @param  Collection<int, DhcpLease>  $leases
     */
    private function enrichRangeWithUsage(DhcpRange $range, Collection $leases): DhcpRange
    {
        $fromLong = ip2long((string) $range->rangeFrom);
        $toLong = ip2long((string) $range->rangeTo);

        if ($fromLong === false || $toLong === false) {
            return $range; // @codeCoverageIgnore
        }

        $total = $toLong - $fromLong + 1;

        $used = $leases->filter(function (DhcpLease $lease) use ($fromLong, $toLong): bool {
            $leaseLong = ip2long($lease->ip);

            return $leaseLong !== false && $leaseLong >= $fromLong && $leaseLong <= $toLong;
        })->count();

        return new DhcpRange(
            interface: $range->interface,
            type: $range->type,
            subnet: $range->subnet,
            rangeFrom: $range->rangeFrom,
            rangeTo: $range->rangeTo,
            prefix: $range->prefix,
            gateway: $range->gateway,
            description: $range->description,
            totalAddresses: (string) $total,
            usedAddresses: $used,
            utilisation: $total > 0 ? round($used / $total, 4) : 0.0,
        );
    }
}
