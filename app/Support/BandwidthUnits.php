<?php

declare(strict_types=1);

namespace App\Support;

final class BandwidthUnits
{
    public const BITS_PER_BYTE = 8;

    public const SECONDS_PER_MINUTE = 60;

    public const SECONDS_PER_HOUR = 3600;

    public const SECONDS_PER_DAY = 86400;

    public const DEFAULT_RANGE_SECONDS = self::SECONDS_PER_DAY;

    public static function bitsToBytes(float $bits): float
    {
        return $bits / self::BITS_PER_BYTE;
    }
}
