<?php

declare(strict_types=1);

namespace App\Enums;

enum AddressFamily: string
{
    case IPv4 = 'ipv4';
    case IPv6 = 'ipv6';

    public static function fromIp(string $ip): self
    {
        return str_contains($ip, ':') ? self::IPv6 : self::IPv4;
    }
}
