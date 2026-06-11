<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Helpers for working with IPv6 CIDR prefixes.
 */
final class Ipv6Prefix
{
    /**
     * Compute the exact total address count of an IPv6 prefix.
     *
     * Returns 2^(128 - length) as an exact decimal numeric string via BCMath,
     * so counts beyond PHP_INT_MAX (e.g. a /64's 2^64) are preserved.
     * Returns null for malformed input: missing or non-numeric /length,
     * lengths outside 0–128, or a network part that is not an IPv6 address
     * (IPv4 networks are rejected — this helper is IPv6-specific).
     *
     * @return numeric-string|null
     */
    public static function totalAddresses(string $cidr): ?string
    {
        if (preg_match('#^(.+)/(\d{1,3})$#', $cidr, $matches) !== 1) {
            return null;
        }

        $length = (int) $matches[2];

        if ($length > 128) {
            return null;
        }

        if (filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }

        return bcpow('2', (string) (128 - $length), 0);
    }
}
