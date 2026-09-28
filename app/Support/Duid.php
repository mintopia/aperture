<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Helpers for extracting MAC addresses from DHCPv6 DUIDs (RFC 8415).
 */
final class Duid
{
    /**
     * Extract the hardware MAC address embedded in a DHCPv6 DUID.
     *
     * Supports DUID-LL (type 0003) and DUID-LLT (type 0001) where the
     * hardware-type field is Ethernet (0001); the input may be colon,
     * hyphen, space, or dot separated hex, or plain hex.
     *
     * Returns null for DUID-EN (0002), DUID-UUID (0004), non-Ethernet
     * hardware types, wrong-length input, or non-hex input.
     *
     * @return string|null uppercase colon-separated MAC, e.g. "AA:BB:CC:DD:EE:FF"
     */
    public static function macAddress(string $duid): ?string
    {
        $hex = strtoupper(str_replace([':', '-', ' ', '.'], '', $duid));

        if ($hex === '' || ! ctype_xdigit($hex)) {
            return null;
        }

        // DUID-LLT: type(2B) + hw-type(2B) + time(4B) + MAC(6B) = 28 hex chars.
        if (str_starts_with($hex, '0001')) {
            if (strlen($hex) !== 28 || substr($hex, 4, 4) !== '0001') {
                return null;
            }

            return implode(':', str_split(substr($hex, 16, 12), 2));
        }

        // DUID-LL: type(2B) + hw-type(2B) + MAC(6B) = 20 hex chars.
        if (str_starts_with($hex, '0003')) {
            if (strlen($hex) !== 20 || substr($hex, 4, 4) !== '0001') {
                return null;
            }

            return implode(':', str_split(substr($hex, 8, 12), 2));
        }

        return null;
    }
}
