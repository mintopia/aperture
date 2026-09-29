<?php

declare(strict_types=1);

namespace App\Services\Cisco;

use App\Enums\AddressFamily;
use App\Models\IpAddress;
use App\Services\Dhcp\RangeUsageCalculator;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\SwitchCommandTransportInterface;
use App\Services\NetworkSwitch\IosOutputParser;
use App\Services\ValueObjects\DhcpFetchStatus;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpPoolStatus;
use App\Services\ValueObjects\DhcpRange;
use App\Services\ValueObjects\DhcpSnapshot;
use App\Support\Ipv6Prefix;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Throwable;

class CiscoDhcpService implements DhcpInterface
{
    public function __construct(
        private SwitchCommandTransportInterface $transport,
        private IosOutputParser $parser,
        private string $poolSize = '0',
        private bool $ipv6Enabled = true,
        private string $timezone = 'UTC',
    ) {}

    public function snapshot(): DhcpSnapshot
    {
        ['data' => $data, 'ipv4' => $ipv4Ok, 'ipv6' => $ipv6Ok] = $this->fetch();

        $ipv6Active = $this->ipv6Enabled && $ipv6Ok;

        return DhcpSnapshot::create(
            $this->buildLeases($data, $ipv6Active),
            $this->buildRanges($data, $ipv6Active),
            new DhcpFetchStatus($ipv4Ok),
            new DhcpFetchStatus($ipv6Ok),
            [AddressFamily::IPv4->value => $this->ipv4PoolStatus($data)],
        );
    }

    public function getLease(string $ipAddress): ?DhcpLease
    {
        $needle = IpAddress::normalize($ipAddress);

        return $this->snapshot()->leases->first(fn (DhcpLease $lease): bool => IpAddress::normalize($lease->ip) === $needle);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function ipv4PoolStatus(array $data): DhcpPoolStatus
    {
        /** @var array<int, array{name: string, total: string, leased: string}> $poolStats */
        $poolStats = $data['pool_stats'] ?? [];

        $poolSizeInt = (int) $this->poolSize;

        if ($poolSizeInt > 0) {
            $total = $poolSizeInt;
        } else {
            $total = array_reduce(
                $poolStats,
                fn (int $carry, array $pool): int => $carry + (int) $pool['total'],
                0
            );
        }

        $used = array_reduce(
            $poolStats,
            fn (int $carry, array $pool): int => $carry + (int) $pool['leased'],
            0
        );
        $available = max(0, $total - $used);
        $utilisation = $total > 0 ? round($used / $total, 4) : 0.0;

        return new DhcpPoolStatus(
            total: $total,
            used: $used,
            available: $available,
            utilisation: $utilisation,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Collection<int, DhcpLease>
     */
    private function buildLeases(array $data, bool $ipv6Active): Collection
    {
        /** @var array<int, array{ip: string, mac: string|null, expires: string}> $ipv4Bindings */
        $ipv4Bindings = $data['ipv4_bindings'] ?? [];

        $leases = collect($ipv4Bindings)
            ->map(fn (array $binding): DhcpLease => new DhcpLease(
                ip: $binding['ip'],
                mac: $binding['mac'],
                hostname: '',
                expires: $this->toUtc($binding['expires']),
            ));

        if ($ipv6Active) {
            /** @var array<int, array{ip: string, mac: string|null, expires: string}> $ipv6Bindings */
            $ipv6Bindings = $data['ipv6_bindings'] ?? [];

            $ipv6Leases = collect($ipv6Bindings)
                ->map(fn (array $binding): DhcpLease => new DhcpLease(
                    ip: $binding['ip'],
                    mac: $binding['mac'],
                    hostname: '',
                    expires: $this->toUtc($binding['expires']),
                ));

            $leases = $leases->concat($ipv6Leases);
        }

        return $leases->values();
    }

    private function toUtc(string $expires): string
    {
        try {
            $local = Date::createFromFormat('M d Y h:i A', trim($expires), $this->timezone);
        } catch (Throwable) {
            return $expires;
        }

        return $local instanceof Carbon ? $local->utc()->format('Y-m-d H:i:s') : $expires;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Collection<int, DhcpRange>
     */
    private function buildRanges(array $data, bool $ipv6Active): Collection
    {
        /** @var array{pools: array<int, array{name: string, network: string, mask: string, gateway: string}>, excluded: array<int, array{start: string, end: string}>} $poolConfig */
        $poolConfig = $data['pool_config'] ?? ['pools' => [], 'excluded' => []];

        $effectiveRanges = $this->parser->computeEffectiveRanges($poolConfig);

        /** @var array<int, array{ip: string, mac: string|null, expires: string}> $ipv4Bindings */
        $ipv4Bindings = $data['ipv4_bindings'] ?? [];

        $ranges = collect($effectiveRanges)
            ->map(function (array $range) use ($ipv4Bindings): DhcpRange {
                /** @var numeric-string $total */
                $total = $range['total_addresses'];
                $used = $this->countBindingsInRange($ipv4Bindings, $range['range_from'], $range['range_to']);
                $utilisation = RangeUsageCalculator::utilisation($used, $total);

                return new DhcpRange(
                    interface: $range['name'],
                    type: AddressFamily::IPv4,
                    subnet: $range['subnet'],
                    rangeFrom: $range['range_from'],
                    rangeTo: $range['range_to'],
                    prefix: null,
                    gateway: $range['gateway'] !== '' ? $range['gateway'] : null,
                    description: null,
                    totalAddresses: $total,
                    usedAddresses: $used,
                    utilisation: $utilisation,
                );
            });

        if ($ipv6Active) {
            /** @var array{pools: array<int, array{name: string, prefix: string}>} $ipv6Config */
            $ipv6Config = $data['ipv6_pool_config'] ?? ['pools' => []];

            /** @var array<int, array{ip: string, mac: string|null, expires: string}> $ipv6Bindings */
            $ipv6Bindings = $data['ipv6_bindings'] ?? [];

            $ipv6Ranges = collect($ipv6Config['pools'])
                ->map(function (array $pool) use ($ipv6Bindings): DhcpRange {
                    $prefix = $pool['prefix'] !== '' ? IpAddress::normalize($pool['prefix']) : null;
                    $total = $prefix !== null ? Ipv6Prefix::totalAddresses($prefix) : null;
                    $used = $this->countIpv6BindingsInPrefix($ipv6Bindings, $prefix);
                    $utilisation = $total !== null && $used !== null
                        ? RangeUsageCalculator::utilisation($used, $total)
                        : null;

                    return new DhcpRange(
                        interface: $pool['name'],
                        type: AddressFamily::IPv6,
                        subnet: null,
                        rangeFrom: null,
                        rangeTo: null,
                        prefix: $prefix,
                        gateway: null,
                        description: null,
                        totalAddresses: $total,
                        usedAddresses: $used,
                        utilisation: $utilisation,
                    );
                });

            $ranges = $ranges->concat($ipv6Ranges);
        }

        return $ranges->values();
    }

    /**
     * Count IPv4 bindings whose address falls within the given range (inclusive).
     *
     * @param  array<int, array{ip: string, mac: string|null, expires: string}>  $bindings
     */
    private function countBindingsInRange(array $bindings, string $rangeFrom, string $rangeTo): int
    {
        $start = ip2long($rangeFrom);
        $end = ip2long($rangeTo);

        if ($start === false || $end === false) {
            return 0;
        }

        return count(array_filter($bindings, function (array $binding) use ($start, $end): bool {
            $ip = ip2long($binding['ip']);

            return $ip !== false && $ip >= $start && $ip <= $end;
        }));
    }

    /**
     * Count IPv6 bindings whose address falls within the given prefix.
     *
     * Comparison uses inet_pton with byte/bit masking on the prefix length —
     * not string matching — so compressed and expanded notations agree.
     * Returns null when the prefix is missing or unparsable (usage unknown);
     * a valid prefix with no matching bindings yields a known count of 0.
     *
     * @param  array<int, array{ip: string, mac: string|null, expires: string}>  $bindings
     */
    private function countIpv6BindingsInPrefix(array $bindings, ?string $prefix): ?int
    {
        if ($prefix === null || preg_match('#^(.+)/(\d+)$#', $prefix, $matches) !== 1) {
            return null;
        }

        $length = (int) $matches[2];
        $network = $this->packIpv6($matches[1]);

        if ($network === null || $length > 128) {
            return null;
        }

        return count(array_filter(
            $bindings,
            function (array $binding) use ($network, $length): bool {
                $packed = $this->packIpv6($binding['ip']);

                return $packed !== null && $this->ipv6PrefixMatches($packed, $network, $length);
            }
        ));
    }

    /**
     * Pack an IPv6 address into its 16-byte binary form, or null if invalid.
     */
    private function packIpv6(string $address): ?string
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }

        $packed = inet_pton($address);

        return $packed !== false ? $packed : null;
    }

    /**
     * Compare two packed IPv6 addresses on the first $length bits.
     */
    private function ipv6PrefixMatches(string $packed, string $network, int $length): bool
    {
        $fullBytes = intdiv($length, 8);
        $remainingBits = $length % 8;

        if ($fullBytes > 0 && substr($packed, 0, $fullBytes) !== substr($network, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($packed[$fullBytes]) & $mask) === (ord($network[$fullBytes]) & $mask);
    }

    /**
     * @return array{data: array<string, mixed>, ipv4: bool, ipv6: bool}
     */
    private function fetch(): array
    {
        $commands = [
            'show ip dhcp binding',
            'show ip dhcp pool',
            'show running-config | section ip dhcp',
        ];

        if ($this->ipv6Enabled) {
            $commands[] = 'show ipv6 dhcp binding';
            $commands[] = 'show ipv6 dhcp pool';
            $commands[] = 'show running-config | section ipv6 dhcp pool';
        }

        try {
            /** @var array<string, string> $results */
            $results = $this->transport->executeMultiple($commands);

            $data = [];
            $ipv4Ok = false;
            $ipv6Ok = false;

            $ipv4BindingOutput = $results['show ip dhcp binding'] ?? '';

            // A missing or error result is a failed fetch, not an empty one.
            if (! array_key_exists('show ip dhcp binding', $results)
                || $this->parser->isErrorOutput($ipv4BindingOutput)) {
                Log::warning('CiscoDhcpService: IPv4 fetch failed — missing or error output from switch');
                $data['ipv4_bindings'] = [];
                $data['pool_stats'] = [];
                $data['pool_config'] = ['pools' => [], 'excluded' => []];
            } else {
                $data['ipv4_bindings'] = $this->parser->parseDhcpBindingTable($ipv4BindingOutput);
                $data['pool_stats'] = $this->parser->parseDhcpPoolStats(
                    $results['show ip dhcp pool'] ?? ''
                );
                $data['pool_config'] = $this->parser->parseDhcpPoolConfig(
                    $results['show running-config | section ip dhcp'] ?? ''
                );

                $ipv4Ok = true;
            }

            // Parse IPv6 (failure here does not block IPv4)
            if ($this->ipv6Enabled) {
                try {
                    $ipv6BindingOutput = $results['show ipv6 dhcp binding'] ?? '';

                    if ($this->parser->isErrorOutput($ipv6BindingOutput)) {
                        Log::warning('CiscoDhcpService: IPv6 fetch failed — error output from switch');
                        $data['ipv6_bindings'] = [];
                        $data['ipv6_pool_stats'] = [];
                        $data['ipv6_pool_config'] = ['pools' => []];
                        $ipv6Ok = false;
                    } else {
                        $data['ipv6_bindings'] = $this->parser->parseDhcpv6BindingTable($ipv6BindingOutput);
                        $data['ipv6_pool_stats'] = $this->parser->parseDhcpv6PoolStats(
                            $results['show ipv6 dhcp pool'] ?? ''
                        );
                        $data['ipv6_pool_config'] = $this->parser->parseDhcpv6PoolConfig(
                            $results['show running-config | section ipv6 dhcp pool'] ?? ''
                        );

                        $ipv6Ok = true;
                    }
                } catch (Throwable $e) {
                    Log::warning('CiscoDhcpService: IPv6 fetch failed', ['error' => $e->getMessage()]);
                    $ipv6Ok = false;
                }
            }
        } finally {
            $this->transport->disconnect();
        }

        return ['data' => $data, 'ipv4' => $ipv4Ok, 'ipv6' => $ipv6Ok];
    }
}
