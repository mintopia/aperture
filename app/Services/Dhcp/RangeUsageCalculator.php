<?php

declare(strict_types=1);

namespace App\Services\Dhcp;

use App\Services\ValueObjects\DhcpPoolStatus;
use App\Services\ValueObjects\DhcpRange;

final class RangeUsageCalculator
{
    /**
     * @param  iterable<string>  $leaseIps
     */
    public static function enrich(DhcpRange $range, iterable $leaseIps): DhcpRange
    {
        if ($range->rangeFrom === null || $range->rangeTo === null) {
            return $range;
        }

        $from = inet_pton($range->rangeFrom);
        $to = inet_pton($range->rangeTo);

        if ($from === false || $to === false || strlen($from) !== strlen($to)) {
            return $range;
        }

        $total = self::addressCount($from, $to);
        $used = 0;

        foreach ($leaseIps as $ip) {
            $packed = inet_pton($ip);

            if ($packed !== false && strlen($packed) === strlen($from) && $packed >= $from && $packed <= $to) {
                $used++;
            }
        }

        return self::withUsage($range, $total, $used);
    }

    /**
     * @param  numeric-string  $total
     */
    public static function withUsage(DhcpRange $range, string $total, int $used): DhcpRange
    {
        return new DhcpRange(
            interface: $range->interface,
            type: $range->type,
            subnet: $range->subnet,
            rangeFrom: $range->rangeFrom,
            rangeTo: $range->rangeTo,
            prefix: $range->prefix,
            gateway: $range->gateway,
            description: $range->description,
            totalAddresses: $total,
            usedAddresses: $used,
            utilisation: self::utilisation($used, $total),
        );
    }

    /**
     * @param  iterable<DhcpRange>  $ranges
     */
    public static function poolStatus(iterable $ranges): DhcpPoolStatus
    {
        $total = '0';
        $used = '0';

        foreach ($ranges as $range) {
            $total = bcadd($total, self::nonNegative($range->totalAddresses ?? '0'), 0);
            $used = bcadd($used, (string) ($range->usedAddresses ?? 0), 0);
        }

        $available = bccomp($total, $used, 0) > 0 ? bcsub($total, $used, 0) : '0';

        return new DhcpPoolStatus(
            total: self::fitInt($total),
            used: (int) $used,
            available: self::fitInt($available),
            utilisation: self::utilisation($used, $total),
        );
    }

    /**
     * @param  numeric-string|int  $used
     * @param  numeric-string|int  $total
     */
    public static function utilisation(string|int $used, string|int $total): float
    {
        $total = (string) $total;

        if (bccomp($total, '0', 0) <= 0) {
            return 0.0;
        }

        return round((float) bcdiv((string) $used, $total, 8), 4);
    }

    /**
     * @return numeric-string
     */
    private static function addressCount(string $from, string $to): string
    {
        $count = bcadd(bcsub(self::toDecimal($to), self::toDecimal($from), 0), '1', 0);

        return bccomp($count, '0', 0) > 0 ? $count : '0';
    }

    /**
     * @return numeric-string
     */
    private static function toDecimal(string $packed): string
    {
        $decimal = '0';

        foreach (str_split($packed) as $byte) {
            $decimal = bcadd(bcmul($decimal, '256', 0), (string) ord($byte), 0);
        }

        return $decimal;
    }

    /**
     * @param  numeric-string  $value
     * @return numeric-string
     */
    private static function nonNegative(string $value): string
    {
        return bccomp($value, '0', 0) > 0 ? $value : '0';
    }

    /**
     * @param  numeric-string  $value
     * @return int|numeric-string
     */
    private static function fitInt(string $value): int|string
    {
        return bccomp($value, (string) PHP_INT_MAX, 0) > 0 ? $value : (int) $value;
    }
}
