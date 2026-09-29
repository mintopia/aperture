<?php

declare(strict_types=1);

namespace App\Services\Kea;

use App\Enums\AddressFamily;
use App\Services\Dhcp\RangeUsageCalculator;
use App\Services\Interfaces\DhcpInterface;
use App\Services\ValueObjects\DhcpFetchStatus;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpRange;
use App\Services\ValueObjects\DhcpSnapshot;
use App\Support\Duid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Throwable;

class KeaDhcpService implements DhcpInterface
{
    private const PAGE_LIMIT = 1000;

    private const LEASE_STATE_ACTIVE = 0;

    private const LEASE_STATE_DECLINED = 1;

    /**
     * Kea's documented keyword for the first `lease{4,6}-get-page` request.
     */
    private const FIRST_PAGE_CURSOR = 'start';

    /**
     * Safety cap on pages fetched per sync, in case Kea's `from` cursor
     * never advances (misbehaving API) — prevents an infinite loop.
     */
    private const MAX_PAGES = 10_000;

    public function __construct(
        private readonly ?KeaClient $ipv4Client,
        private readonly ?KeaClient $ipv6Client = null,
    ) {}

    public function snapshot(): DhcpSnapshot
    {
        $now = Date::now()->getTimestamp();

        /** @var array<string, Collection<int, DhcpLease>> $leases */
        $leases = [];
        /** @var array<string, Collection<int, DhcpLease>> $declined */
        $declined = [];
        /** @var array<string, bool> $leasesOk */
        $leasesOk = [];

        foreach (AddressFamily::cases() as $family) {
            $leases[$family->value] = collect();
            $declined[$family->value] = collect();
            $client = $this->client($family);

            if (! $client instanceof KeaClient) {
                $leasesOk[$family->value] = true;

                continue;
            }

            try {
                $leases[$family->value] = $this->fetchFamilyLeases($client, $family, $now, $declined[$family->value]);
                $leasesOk[$family->value] = true;
            } catch (Throwable $throwable) {
                Log::warning(sprintf('Kea %s lease fetch failed', $this->label($family)), ['error' => $throwable->getMessage()]);
                $leasesOk[$family->value] = false;
            }
        }

        $ranges = collect();
        /** @var array<string, bool> $rangesOk */
        $rangesOk = [];

        foreach (AddressFamily::cases() as $family) {
            $rangesOk[$family->value] = true;
            $client = $this->client($family);

            if (! $client instanceof KeaClient || ! $leasesOk[$family->value]) {
                continue;
            }

            $usageIps = $leases[$family->value]->concat($declined[$family->value])
                ->map(fn (DhcpLease $lease): string => $lease->ip);

            $fetched = $this->fetchRanges($client, $family, $usageIps);

            if (! $fetched instanceof Collection) {
                $rangesOk[$family->value] = false;

                continue;
            }

            $ranges = $ranges->concat($fetched);
        }

        return DhcpSnapshot::create(
            $leases[AddressFamily::IPv4->value]->concat($leases[AddressFamily::IPv6->value]),
            $ranges,
            new DhcpFetchStatus($leasesOk[AddressFamily::IPv4->value], $rangesOk[AddressFamily::IPv4->value]),
            new DhcpFetchStatus($leasesOk[AddressFamily::IPv6->value], $rangesOk[AddressFamily::IPv6->value]),
        );
    }

    public function getLease(string $ipAddress): ?DhcpLease
    {
        $family = match (true) {
            filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => AddressFamily::IPv4,
            filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => AddressFamily::IPv6,
            default => null,
        };

        if ($family === null) {
            return null;
        }

        return $this->fetchSingleLease($family, $ipAddress);
    }

    private function client(AddressFamily $family): ?KeaClient
    {
        return $family === AddressFamily::IPv4 ? $this->ipv4Client : $this->ipv6Client;
    }

    private function label(AddressFamily $family): string
    {
        return $family === AddressFamily::IPv4 ? 'IPv4' : 'IPv6';
    }

    /**
     * @param  Collection<int, string>  $usageIps
     * @return Collection<int, DhcpRange>|null
     */
    private function fetchRanges(KeaClient $client, AddressFamily $family, Collection $usageIps): ?Collection
    {
        $isV4 = $family === AddressFamily::IPv4;
        $protocolKey = $isV4 ? 'Dhcp4' : 'Dhcp6';
        $subnetKey = $isV4 ? 'subnet4' : 'subnet6';

        try {
            $entry = $client->sendCommand('config-get');
        } catch (Throwable $throwable) {
            Log::warning($isV4 ? 'Kea config-get failed' : 'Kea IPv6 config-get failed', ['error' => $throwable->getMessage()]);

            return null;
        }

        $arguments = $entry['arguments'] ?? null;
        $protocolConfig = is_array($arguments) ? ($arguments[$protocolKey] ?? null) : null;

        if (! is_array($protocolConfig)) {
            return collect();
        }

        $ranges = collect();

        $this->collectSubnets($ranges, $protocolConfig[$subnetKey] ?? [], '', $family);

        $sharedNetworks = $protocolConfig['shared-networks'] ?? [];

        if (is_array($sharedNetworks)) {
            foreach ($sharedNetworks as $sharedNetwork) {
                if (! is_array($sharedNetwork)) {
                    continue;
                }

                $name = $sharedNetwork['name'] ?? null;
                $fallbackInterface = is_string($name) && $name !== '' ? $name : '';

                $this->collectSubnets($ranges, $sharedNetwork[$subnetKey] ?? [], $fallbackInterface, $family);
            }
        }

        return $ranges->map(fn (DhcpRange $range): DhcpRange => RangeUsageCalculator::enrich($range, $usageIps))->values();
    }

    private function fetchSingleLease(AddressFamily $family, string $ipAddress): ?DhcpLease
    {
        $client = $this->client($family);

        if (! $client instanceof KeaClient) {
            return null;
        }

        $isV4 = $family === AddressFamily::IPv4;
        $command = $isV4 ? 'lease4-get' : 'lease6-get';
        $arguments = $isV4 ? ['ip-address' => $ipAddress] : ['ip-address' => $ipAddress, 'type' => 'IA_NA'];

        try {
            $entry = $client->sendCommand($command, $arguments);
        } catch (Throwable $throwable) {
            Log::warning(sprintf('Kea %s failed', $command), ['ip' => $ipAddress, 'error' => $throwable->getMessage()]);

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

        return $this->buildLease($lease, $ipAddress, $expiresAt, $family);
    }

    /**
     * @param  Collection<int, DhcpLease>  $declined
     * @return Collection<int, DhcpLease>
     */
    private function fetchFamilyLeases(KeaClient $client, AddressFamily $family, int $now, Collection $declined): Collection
    {
        $command = $family === AddressFamily::IPv4 ? 'lease4-get-page' : 'lease6-get-page';
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

            $lastIp = $this->processPage($pageLeases, $leases, $declined, $now, $family);

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
     * @param  Collection<int, DhcpLease>  $declined
     */
    private function processPage(array $pageLeases, Collection $leases, Collection $declined, int $now, AddressFamily $family): ?string
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

            if ($family === AddressFamily::IPv6 && $this->isPrefixDelegation($lease)) {
                continue;
            }

            if ($this->isDeclined($lease, $now)) {
                $declined->push($this->buildLease($lease, $ip, $this->expiresAt($lease), $family));

                continue;
            }

            if (! $this->isKeepable($lease, $now)) {
                continue;
            }

            $leases->push($this->buildLease($lease, $ip, $this->expiresAt($lease), $family));
        }

        return $lastIp;
    }

    /**
     * IA_PD entries are Kea's DHCPv6 prefix-delegation leases (router-to-router),
     * not host leases, and appear interleaved with IA_NA entries on the same page.
     *
     * @param  array<string, mixed>  $lease
     */
    private function isPrefixDelegation(array $lease): bool
    {
        return ($lease['type'] ?? null) === 'IA_PD';
    }

    /**
     * @param  array<string, mixed>  $lease
     */
    private function isDeclined(array $lease, int $now): bool
    {
        return (int) ($lease['state'] ?? -1) === self::LEASE_STATE_DECLINED && $this->expiresAt($lease) > $now;
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
    private function buildLease(array $lease, string $ip, int $expiresAt, AddressFamily $family): DhcpLease
    {
        $hostname = $lease['hostname'] ?? null;

        return new DhcpLease(
            ip: $ip,
            mac: $this->deriveMac($lease, $family),
            hostname: is_string($hostname) ? $hostname : '',
            expires: Date::createFromTimestamp($expiresAt)->toIso8601String(),
            macFromDuid: $this->macIsDuidDerived($lease, $family),
        );
    }

    /**
     * @param  array<string, mixed>  $lease
     */
    private function macIsDuidDerived(array $lease, AddressFamily $family): bool
    {
        $hw = $lease['hw-address'] ?? null;

        return $family === AddressFamily::IPv6 && ! (is_string($hw) && $hw !== '') && $this->deriveMac($lease, $family) !== null;
    }

    /**
     * @param  array<string, mixed>  $lease
     */
    private function deriveMac(array $lease, AddressFamily $family): ?string
    {
        $mac = $lease['hw-address'] ?? null;
        $mac = is_string($mac) && $mac !== '' ? $mac : null;

        if ($mac === null && $family === AddressFamily::IPv6) {
            $duid = $lease['duid'] ?? null;

            if (is_string($duid) && $duid !== '') {
                $mac = Duid::macAddress($duid);
            }
        }

        return $mac;
    }

    /**
     * @param  Collection<int, DhcpRange>  $ranges
     */
    private function collectSubnets(Collection $ranges, mixed $subnets, string $fallbackInterface, AddressFamily $family): void
    {
        if (! is_array($subnets)) {
            return;
        }

        foreach ($subnets as $subnet) {
            $this->collectSubnetRanges($ranges, $subnet, $fallbackInterface, $family);
        }
    }

    /**
     * @param  Collection<int, DhcpRange>  $ranges
     */
    private function collectSubnetRanges(Collection $ranges, mixed $subnet, string $fallbackInterface, AddressFamily $family): void
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
            $range = $this->buildRange($family, $pool, $cidr, $interface, $subnetLabel);

            if ($range instanceof DhcpRange) {
                $ranges->push($range);
            }
        }
    }

    private function buildRange(AddressFamily $family, mixed $pool, string $cidr, string $interface, ?string $subnetLabel): ?DhcpRange
    {
        if (! is_array($pool)) {
            return null;
        }

        $poolString = $pool['pool'] ?? null;

        if (! is_string($poolString) || $poolString === '') {
            return null;
        }

        $bounds = $family === AddressFamily::IPv4
            ? $this->parseRangeBounds($poolString)
            : $this->parseIpv6RangeBounds($poolString);

        if ($bounds === null) {
            return null;
        }

        [$from, $to] = $bounds;

        return new DhcpRange(
            interface: $interface,
            type: $family,
            subnet: $cidr,
            rangeFrom: $from,
            rangeTo: $to,
            prefix: null,
            gateway: null,
            description: $this->poolLabel($pool, $subnetLabel, $cidr, $from, $to),
        );
    }

    /**
     * @param  array<string, mixed>  $pool
     */
    private function poolLabel(array $pool, ?string $subnetLabel, string $cidr, string $from, string $to): string
    {
        return $this->contextName($pool['user-context'] ?? null)
            ?? $subnetLabel
            ?? sprintf('%s (%s–%s)', $cidr, $from, $to);
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
     * @return array{0: string, 1: string}|null
     */
    private function parseIpv6RangeBounds(string $pool): ?array
    {
        if (str_contains($pool, '/')) {
            return $this->parseIpv6CidrRangeBounds($pool);
        }

        if (preg_match('/^\s*([^\s-]+)\s*-\s*([^\s-]+)\s*$/', $pool, $matches) !== 1) {
            return null;
        }

        $fromPacked = filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? inet_pton($matches[1])
            : false;
        $toPacked = filter_var($matches[2], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? inet_pton($matches[2])
            : false;

        if ($fromPacked === false || $toPacked === false || $fromPacked > $toPacked) {
            return null;
        }

        return [$matches[1], $matches[2]];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function parseIpv6CidrRangeBounds(string $cidr): ?array
    {
        if (preg_match('#^(.+)/(\d{1,3})$#', $cidr, $matches) !== 1) {
            return null;
        }

        $prefixLength = (int) $matches[2];

        if ($prefixLength > 128 || filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }

        $network = inet_pton($matches[1]);

        if ($network === false) {
            return null; // @codeCoverageIgnore
        }

        $fullBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;

        $start = $network;
        $end = $network;

        for ($i = $fullBytes + ($remainingBits > 0 ? 1 : 0); $i < 16; $i++) {
            $start[$i] = "\x00";
            $end[$i] = "\xFF";
        }

        if ($remainingBits > 0) {
            $hostMask = 0xFF >> $remainingBits;
            $networkByte = ord($network[$fullBytes]);
            $start[$fullBytes] = chr($networkByte & ~$hostMask);
            $end[$fullBytes] = chr($networkByte | $hostMask);
        }

        $fromAddress = inet_ntop($start);
        $toAddress = inet_ntop($end);

        if ($fromAddress === false || $toAddress === false) {
            return null; // @codeCoverageIgnore
        }

        return [$fromAddress, $toAddress];
    }
}
