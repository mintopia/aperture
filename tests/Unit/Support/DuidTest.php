<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Duid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DuidTest extends TestCase
{
    /**
     * @return array<string, array{string, string|null}>
     */
    public static function duidProvider(): array
    {
        return [
            'DUID-LL colon-separated' => ['00:03:00:01:aa:bb:cc:dd:ee:ff', 'AA:BB:CC:DD:EE:FF'],
            'DUID-LL plain hex' => ['00030001AABBCCDDEEFF', 'AA:BB:CC:DD:EE:FF'],
            'DUID-LL hyphen-separated' => ['00-03-00-01-AA-BB-CC-DD-EE-FF', 'AA:BB:CC:DD:EE:FF'],
            'DUID-LL space-separated' => ['0003 0001 aabbccddeeff', 'AA:BB:CC:DD:EE:FF'],
            'DUID-LL dot-separated' => ['0003.0001.aabb.ccdd.eeff', 'AA:BB:CC:DD:EE:FF'],

            'DUID-LLT plain hex' => ['00010001AABBCCDDEEFF0011AABB', 'EE:FF:00:11:AA:BB'],
            'DUID-LLT colon-separated' => ['00:01:00:01:aa:bb:cc:dd:ee:ff:00:11:aa:bb', 'EE:FF:00:11:AA:BB'],

            'DUID-EN (type 2) returns null' => ['00020000000AABBCCDD', null],
            'DUID-UUID (type 4) returns null' => ['000400000000000000000000000000000001', null],

            'DUID-LL non-Ethernet hw-type (IEEE802)' => ['00030006AABBCCDDEEFF', null],
            'DUID-LLT non-Ethernet hw-type (IEEE802)' => ['00010006AABBCCDDEEFF0011AABB', null],

            'DUID-LL too short' => ['00030001AABBCCDDEE', null],
            'DUID-LL too long' => ['00030001AABBCCDDEEFF00', null],
            'DUID-LLT too short' => ['00010001AABBCCDDEEFF0011AA', null],
            'DUID-LLT too long' => ['00010001AABBCCDDEEFF0011AABB00', null],

            'non-hex characters' => ['00:03:00:01:zz:zz:zz:zz:zz:zz', null],
            'empty string' => ['', null],
        ];
    }

    #[DataProvider('duidProvider')]
    public function test_mac_address(string $duid, ?string $expected): void
    {
        $this->assertSame($expected, Duid::macAddress($duid));
    }
}
