<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;

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
        $ranges = $this->getRanges($isV6);

        foreach ($ranges as $cidr) {
            if ($this->ipInCidr($binary, $cidr, $isV6)) {
                return true;
            }
        }

        return false;
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

    private function ipInCidr(string $ipBinary, string $cidr, bool $isV6): bool
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$subnet, $prefixStr] = $parts;
        $prefix = (int) $prefixStr;

        $subnetBinary = @inet_pton($subnet);
        if ($subnetBinary === false) {
            return false;
        }

        $expectedLength = $isV6 ? 16 : 4;
        if (strlen($ipBinary) !== $expectedLength || strlen($subnetBinary) !== $expectedLength) {
            return false;
        }

        $mask = $this->buildMask($prefix, $expectedLength);

        return ($ipBinary & $mask) === ($subnetBinary & $mask);
    }

    private function buildMask(int $prefix, int $bytes): string
    {
        $mask = '';
        $remaining = $prefix;

        for ($i = 0; $i < $bytes; $i++) {
            if ($remaining >= 8) {
                $mask .= chr(255);
                $remaining -= 8;
            } elseif ($remaining > 0) {
                $mask .= chr(256 - (1 << (8 - $remaining)));
                $remaining = 0;
            } else {
                $mask .= chr(0);
            }
        }

        return $mask;
    }
}
