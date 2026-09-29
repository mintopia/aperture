<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dhcp;

use App\Enums\AddressFamily;
use App\Services\Dhcp\RangeUsageCalculator;
use App\Services\ValueObjects\DhcpRange;
use PHPUnit\Framework\TestCase;

class RangeUsageCalculatorTest extends TestCase
{
    private function range(AddressFamily $type, ?string $from, ?string $to, ?string $total = null, ?int $used = null): DhcpRange
    {
        return new DhcpRange('lan', $type, null, $from, $to, null, null, null, $total, $used);
    }

    public function test_enrich_counts_ipv4_total_and_used_inclusively(): void
    {
        $range = RangeUsageCalculator::enrich(
            $this->range(AddressFamily::IPv4, '10.0.0.10', '10.0.0.19'),
            ['10.0.0.10', '10.0.0.19', '10.0.0.20', '10.0.0.9', '2001:db8::1', 'garbage'],
        );

        $this->assertSame('10', $range->totalAddresses);
        $this->assertSame(2, $range->usedAddresses);
        $this->assertSame(0.2, $range->utilisation);
    }

    public function test_enrich_is_exact_for_ipv6_slash_64(): void
    {
        $range = RangeUsageCalculator::enrich(
            $this->range(AddressFamily::IPv6, '2001:db8:0:1::', '2001:db8:0:1:ffff:ffff:ffff:ffff'),
            ['2001:DB8:0:1::5', '2001:db8:0:2::5'],
        );

        $this->assertSame('18446744073709551616', $range->totalAddresses);
        $this->assertSame(1, $range->usedAddresses);
        $this->assertSame(0.0, $range->utilisation);
    }

    public function test_enrich_returns_range_unchanged_when_bounds_are_unusable(): void
    {
        $noBounds = $this->range(AddressFamily::IPv4, null, null);
        $invalid = $this->range(AddressFamily::IPv4, 'nope', '10.0.0.1');
        $mixed = $this->range(AddressFamily::IPv4, '10.0.0.1', '2001:db8::1');

        $this->assertSame($noBounds, RangeUsageCalculator::enrich($noBounds, []));
        $this->assertSame($invalid, RangeUsageCalculator::enrich($invalid, []));
        $this->assertSame($mixed, RangeUsageCalculator::enrich($mixed, []));
    }

    public function test_enrich_guards_inverted_range(): void
    {
        $range = RangeUsageCalculator::enrich($this->range(AddressFamily::IPv4, '10.0.0.20', '10.0.0.10'), ['10.0.0.15']);

        $this->assertSame('0', $range->totalAddresses);
        $this->assertSame(0, $range->usedAddresses);
        $this->assertSame(0.0, $range->utilisation);
    }

    public function test_utilisation_guards_zero_and_negative_totals(): void
    {
        $this->assertSame(0.0, RangeUsageCalculator::utilisation(5, '0'));
        $this->assertSame(0.0, RangeUsageCalculator::utilisation(5, '-3'));
        $this->assertSame(0.3333, RangeUsageCalculator::utilisation(1, 3));
        $this->assertSame(0.5, RangeUsageCalculator::utilisation('9223372036854775808', '18446744073709551616'));
    }

    public function test_pool_status_sums_ranges_and_keeps_int_when_it_fits(): void
    {
        $status = RangeUsageCalculator::poolStatus([
            $this->range(AddressFamily::IPv4, null, null, '100', 30),
            $this->range(AddressFamily::IPv4, null, null, '100', 20),
            $this->range(AddressFamily::IPv4, null, null),
        ]);

        $this->assertSame(200, $status->total);
        $this->assertSame(50, $status->used);
        $this->assertSame(150, $status->available);
        $this->assertSame(0.25, $status->utilisation);
    }

    public function test_pool_status_returns_exact_numeric_strings_beyond_php_int_max(): void
    {
        $status = RangeUsageCalculator::poolStatus([
            $this->range(AddressFamily::IPv6, null, null, '18446744073709551616', 3),
        ]);

        $this->assertSame('18446744073709551616', $status->total);
        $this->assertSame(3, $status->used);
        $this->assertSame('18446744073709551613', $status->available);
    }

    public function test_pool_status_of_no_ranges_is_zeroed(): void
    {
        $status = RangeUsageCalculator::poolStatus([]);

        $this->assertSame(0, $status->total);
        $this->assertSame(0, $status->used);
        $this->assertSame(0, $status->available);
        $this->assertSame(0.0, $status->utilisation);
    }

    public function test_pool_status_available_never_goes_negative(): void
    {
        $status = RangeUsageCalculator::poolStatus([$this->range(AddressFamily::IPv4, null, null, '10', 15)]);

        $this->assertSame(0, $status->available);
    }
}
