<?php

declare(strict_types=1);

namespace App\Support;

final class Duid
{
    private const DUID_TYPE_LLT = '0001';

    private const DUID_TYPE_LL = '0003';

    private const HARDWARE_TYPE_ETHERNET = '0001';

    private const TYPE_HEX_LENGTH = 4;

    private const HARDWARE_TYPE_HEX_LENGTH = 4;

    private const TIMESTAMP_HEX_LENGTH = 8;

    private const MAC_HEX_LENGTH = 12;

    private const LLT_HEX_LENGTH = self::TYPE_HEX_LENGTH + self::HARDWARE_TYPE_HEX_LENGTH + self::TIMESTAMP_HEX_LENGTH + self::MAC_HEX_LENGTH;

    private const LL_HEX_LENGTH = self::TYPE_HEX_LENGTH + self::HARDWARE_TYPE_HEX_LENGTH + self::MAC_HEX_LENGTH;

    private const LLT_MAC_OFFSET = self::TYPE_HEX_LENGTH + self::HARDWARE_TYPE_HEX_LENGTH + self::TIMESTAMP_HEX_LENGTH;

    private const LL_MAC_OFFSET = self::TYPE_HEX_LENGTH + self::HARDWARE_TYPE_HEX_LENGTH;

    /**
     * Extract the hardware MAC address embedded in a DHCPv6 DUID.
     *
     * Supports DUID-LL and DUID-LLT where the hardware-type field is
     * Ethernet; the input may be colon, hyphen, space, or dot separated
     * hex, or plain hex.
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

        if (str_starts_with($hex, self::DUID_TYPE_LLT)) {
            if (strlen($hex) !== self::LLT_HEX_LENGTH || substr($hex, self::TYPE_HEX_LENGTH, self::HARDWARE_TYPE_HEX_LENGTH) !== self::HARDWARE_TYPE_ETHERNET) {
                return null;
            }

            return implode(':', str_split(substr($hex, self::LLT_MAC_OFFSET, self::MAC_HEX_LENGTH), 2));
        }

        if (str_starts_with($hex, self::DUID_TYPE_LL)) {
            if (strlen($hex) !== self::LL_HEX_LENGTH || substr($hex, self::TYPE_HEX_LENGTH, self::HARDWARE_TYPE_HEX_LENGTH) !== self::HARDWARE_TYPE_ETHERNET) {
                return null;
            }

            return implode(':', str_split(substr($hex, self::LL_MAC_OFFSET, self::MAC_HEX_LENGTH), 2));
        }

        return null;
    }
}
