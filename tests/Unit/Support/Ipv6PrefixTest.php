<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Ipv6Prefix;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Ipv6PrefixTest extends TestCase
{
    /**
     * @return array<string, array{string, string|null}>
     */
    public static function cidrProvider(): array
    {
        return [
            // Exact BCMath totals for any prefix length — no /96 cap.
            'production /64 pool' => ['2a0f:85c1:d91:2100::/64', '18446744073709551616'],
            'uppercase /64 pool' => ['2A0F:85C1:D91:2100::/64', '18446744073709551616'],
            '/96 boundary' => ['2001:db8::/96', '4294967296'],
            '/120 small pool' => ['2001:db8:0:1::/120', '256'],
            '/127 point-to-point' => ['2001:db8::/127', '2'],
            '/128 single address' => ['2001:db8::1/128', '1'],
            '/0 entire address space' => ['::/0', '340282366920938463463374607431768211456'],

            // Malformed input → null.
            'no slash' => ['no-slash', null],
            'garbage' => ['garbage', null],
            'empty string' => ['', null],
            'missing length after slash' => ['2001:db8::/', null],
            'non-numeric length' => ['2001:db8::/abc', null],
            'length out of range' => ['2001:db8::/129', null],
            'negative length' => ['2001:db8::/-1', null],
            'network is not an address' => ['nonsense/64', null],

            // The helper is IPv6-specific: IPv4 networks are rejected.
            'ipv4 cidr' => ['10.0.0.0/24', null],
            'ipv4 network with v6-style length' => ['10.0.0.0/64', null],
        ];
    }

    #[DataProvider('cidrProvider')]
    public function test_total_addresses(string $cidr, ?string $expected): void
    {
        $this->assertSame($expected, Ipv6Prefix::totalAddresses($cidr));
    }

    public function test_total_addresses_returns_numeric_string_usable_with_bcmath(): void
    {
        $total = Ipv6Prefix::totalAddresses('2a0f:85c1:d91:2100::/64');

        $this->assertNotNull($total);
        // The value must be an exact decimal numeric string (not scientific
        // notation, not a float cast) so BCMath consumers can divide it.
        $this->assertMatchesRegularExpression('/^\d+$/', $total);
        $this->assertSame('0.000000', bcdiv('3', $total, 6));
    }
}
