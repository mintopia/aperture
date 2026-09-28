<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

use App\Enums\AddressFamily;
use App\Services\Dhcp\RangeUsageCalculator;
use Illuminate\Support\Collection;

readonly class DhcpSnapshot
{
    /**
     * @param  Collection<int, DhcpLease>  $leases
     * @param  Collection<int, DhcpRange>  $ranges
     * @param  array<string, DhcpPoolStatus>  $poolStatuses  keyed by AddressFamily value
     * @param  array<string, DhcpFetchStatus>  $fetchStatuses  keyed by AddressFamily value
     */
    public function __construct(
        public Collection $leases,
        public Collection $ranges,
        private array $poolStatuses,
        private array $fetchStatuses,
        public bool $unavailable = false,
    ) {}

    /**
     * @param  Collection<int, DhcpLease>  $leases
     * @param  Collection<int, DhcpRange>  $ranges
     * @param  array<string, DhcpPoolStatus>  $poolOverrides  keyed by AddressFamily value; families not listed are derived from ranges
     */
    public static function create(
        Collection $leases,
        Collection $ranges,
        DhcpFetchStatus $ipv4 = new DhcpFetchStatus,
        DhcpFetchStatus $ipv6 = new DhcpFetchStatus,
        array $poolOverrides = [],
    ): self {
        $poolStatuses = [];

        foreach (AddressFamily::cases() as $family) {
            $poolStatuses[$family->value] = $poolOverrides[$family->value]
                ?? RangeUsageCalculator::poolStatus($ranges->filter(fn (DhcpRange $range): bool => $range->type === $family));
        }

        return new self($leases->values(), $ranges->values(), $poolStatuses, [
            AddressFamily::IPv4->value => $ipv4,
            AddressFamily::IPv6->value => $ipv6,
        ]);
    }

    public static function empty(): self
    {
        return self::create(collect(), collect());
    }

    public static function unavailable(): self
    {
        $snapshot = self::empty();

        return new self($snapshot->leases, $snapshot->ranges, $snapshot->poolStatuses, $snapshot->fetchStatuses, true);
    }

    public function poolStatus(AddressFamily $family): DhcpPoolStatus
    {
        return $this->poolStatuses[$family->value];
    }

    public function fetchStatus(AddressFamily $family): DhcpFetchStatus
    {
        return $this->fetchStatuses[$family->value];
    }
}
