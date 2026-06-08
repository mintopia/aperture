<?php

declare(strict_types=1);

namespace App\Services\Cisco;

use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\SwitchCommandTransportInterface;
use App\Services\NetworkSwitch\IosOutputParser;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpPoolStatus;
use App\Services\ValueObjects\DhcpRange;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class CiscoDhcpService implements DhcpInterface
{
    /** @var array<string, mixed>|null */
    private ?array $snapshot = null;

    /** @var array{ipv4: bool, ipv6: bool} */
    private array $fetchStatus = ['ipv4' => false, 'ipv6' => false];

    public function __construct(
        private SwitchCommandTransportInterface $transport,
        private IosOutputParser $parser,
        private string $poolSize = '0',
        private bool $ipv6Enabled = true,
    ) {}

    public function getPoolStatus(): DhcpPoolStatus
    {
        $this->ensureSnapshot();

        /** @var array<int, array{name: string, total: string, leased: string}> $poolStats */
        $poolStats = $this->snapshot['pool_stats'] ?? [];

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
     * @return Collection<int, DhcpLease>
     */
    public function getLeases(): Collection
    {
        $this->ensureSnapshot();

        /** @var array<int, array{ip: string, mac: string|null, expires: string}> $ipv4Bindings */
        $ipv4Bindings = $this->snapshot['ipv4_bindings'] ?? [];

        $leases = collect($ipv4Bindings)
            ->map(fn (array $binding): DhcpLease => new DhcpLease(
                ip: $binding['ip'],
                mac: $binding['mac'],
                hostname: '',
                expires: $binding['expires'],
            ));

        if ($this->ipv6Enabled && $this->fetchStatus['ipv6']) {
            /** @var array<int, array{ip: string, mac: string|null, expires: string}> $ipv6Bindings */
            $ipv6Bindings = $this->snapshot['ipv6_bindings'] ?? [];

            $ipv6Leases = collect($ipv6Bindings)
                ->map(fn (array $binding): DhcpLease => new DhcpLease(
                    ip: $binding['ip'],
                    mac: $binding['mac'],
                    hostname: '',
                    expires: $binding['expires'],
                ));

            $leases = $leases->concat($ipv6Leases);
        }

        return $leases->values();
    }

    public function getLease(string $ipAddress): ?DhcpLease
    {
        return $this->getLeases()->first(fn (DhcpLease $lease): bool => $lease->ip === $ipAddress);
    }

    /**
     * @return Collection<int, DhcpRange>
     */
    public function getRanges(): Collection
    {
        $this->ensureSnapshot();

        /** @var array{pools: array<int, array{name: string, network: string, mask: string, gateway: string}>, excluded: array<int, array{start: string, end: string}>} $poolConfig */
        $poolConfig = $this->snapshot['pool_config'] ?? ['pools' => [], 'excluded' => []];

        $effectiveRanges = $this->parser->computeEffectiveRanges($poolConfig);

        $ranges = collect($effectiveRanges)
            ->map(fn (array $range): DhcpRange => new DhcpRange(
                interface: $range['name'],
                type: 'ipv4',
                subnet: $range['subnet'],
                rangeFrom: $range['range_from'],
                rangeTo: $range['range_to'],
                prefix: null,
                gateway: $range['gateway'] !== '' ? $range['gateway'] : null,
                description: null,
                totalAddresses: (int) $range['total_addresses'],
            ));

        if ($this->ipv6Enabled && $this->fetchStatus['ipv6']) {
            /** @var array{pools: array<int, array{name: string, prefix: string}>} $ipv6Config */
            $ipv6Config = $this->snapshot['ipv6_pool_config'] ?? ['pools' => []];

            $ipv6Ranges = collect($ipv6Config['pools'])
                ->map(fn (array $pool): DhcpRange => new DhcpRange(
                    interface: $pool['name'],
                    type: 'ipv6',
                    subnet: null,
                    rangeFrom: null,
                    rangeTo: null,
                    prefix: $pool['prefix'] !== '' ? $pool['prefix'] : null,
                    gateway: null,
                    description: null,
                ));

            $ranges = $ranges->concat($ipv6Ranges);
        }

        return $ranges->values();
    }

    /**
     * @return array{ipv4: bool, ipv6: bool}
     */
    public function getFetchStatus(): array
    {
        $this->ensureSnapshot();

        return $this->fetchStatus;
    }

    public function resetSnapshot(): void
    {
        $this->snapshot = null;
        $this->fetchStatus = ['ipv4' => false, 'ipv6' => false];
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

            $this->snapshot = [];

            // Parse IPv4
            $this->snapshot['ipv4_bindings'] = $this->parser->parseDhcpBindingTable(
                $results['show ip dhcp binding'] ?? ''
            );
            $this->snapshot['pool_stats'] = $this->parser->parseDhcpPoolStats(
                $results['show ip dhcp pool'] ?? ''
            );
            $this->snapshot['pool_config'] = $this->parser->parseDhcpPoolConfig(
                $results['show running-config | section ip dhcp'] ?? ''
            );

            $this->fetchStatus['ipv4'] = true;

            // Parse IPv6 (failure here does not block IPv4)
            if ($this->ipv6Enabled) {
                try {
                    $ipv6BindingOutput = $results['show ipv6 dhcp binding'] ?? '';

                    if ($this->parser->isErrorOutput($ipv6BindingOutput)) {
                        Log::warning('CiscoDhcpService: IPv6 fetch failed — error output from switch');
                        $this->snapshot['ipv6_bindings'] = [];
                        $this->snapshot['ipv6_pool_stats'] = [];
                        $this->snapshot['ipv6_pool_config'] = ['pools' => []];
                        $this->fetchStatus['ipv6'] = false;
                    } else {
                        $this->snapshot['ipv6_bindings'] = $this->parser->parseDhcpv6BindingTable($ipv6BindingOutput);
                        $this->snapshot['ipv6_pool_stats'] = $this->parser->parseDhcpv6PoolStats(
                            $results['show ipv6 dhcp pool'] ?? ''
                        );
                        $this->snapshot['ipv6_pool_config'] = $this->parser->parseDhcpv6PoolConfig(
                            $results['show running-config | section ipv6 dhcp pool'] ?? ''
                        );

                        $this->fetchStatus['ipv6'] = true;
                    }
                } catch (Throwable $e) {
                    Log::warning('CiscoDhcpService: IPv6 fetch failed', ['error' => $e->getMessage()]);
                    $this->fetchStatus['ipv6'] = false;
                }
            }
        } finally {
            $this->transport->disconnect();
        }
    }
}
