<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Symfony\Component\HttpFoundation\IpUtils;

class NetworkRangeService
{
    /** @var array<string, list<string>> */
    private array $cache = [];

    public function isManaged(string $ip): bool
    {
        $binary = @inet_pton($ip);
        if ($binary === false) {
            return false;
        }

        $isV6 = str_contains($ip, ':');

        return IpUtils::checkIp($ip, $this->getRanges($isV6));
    }

    /**
     * @return list<string>
     */
    private function getRanges(bool $isV6): array
    {
        $key = $isV6 ? 'network.managed_ranges_v6' : 'network.managed_ranges_v4';

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $raw = Setting::get($key);
        $default = $isV6 ? ['::/0'] : ['0.0.0.0/0'];

        $decoded = $raw !== null ? json_decode((string) $raw, true) : null;

        $this->cache[$key] = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : $default;

        return $this->cache[$key];
    }

    /**
     * @param  4|6  $family
     */
    public static function isValidCidr(string $cidr, int $family): bool
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$ip, $prefixStr] = $parts;

        if (! ctype_digit($prefixStr)) {
            return false;
        }

        $prefix = (int) $prefixStr;
        $maxPrefix = $family === 4 ? 32 : 128;

        if ($prefix < 0 || $prefix > $maxPrefix) {
            return false;
        }

        $binary = @inet_pton($ip);
        if ($binary === false) {
            return false;
        }

        $expectedLength = $family === 4 ? 4 : 16;

        return strlen($binary) === $expectedLength;
    }
}
