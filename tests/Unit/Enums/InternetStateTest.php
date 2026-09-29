<?php

namespace Tests\Unit\Enums;

use App\Enums\InternetState;
use App\Models\IpAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InternetStateTest extends TestCase
{
    /**
     * @return array<string, array{0: bool|null, 1: InternetState, 2: bool}>
     */
    public static function states(): array
    {
        return [
            'true' => [true, InternetState::Allowed, true],
            'false' => [false, InternetState::Blocked, false],
            'null' => [null, InternetState::Undecided, false],
        ];
    }

    #[DataProvider('states')]
    public function test_column_round_trip_and_allow_rule(?bool $column, InternetState $state, bool $allowed): void
    {
        $this->assertSame($state, InternetState::fromColumn($column));
        $this->assertSame($column, $state->toColumn());
        $this->assertSame($allowed, $state->isAllowed());
    }

    public function test_sort_key_orders_ipv4_as_mapped_ipv6(): void
    {
        $this->assertSame('00000000000000000000ffff01020304', IpAddress::sortKey('1.2.3.4'));
        $this->assertSame(IpAddress::sortKey('1.2.3.4'), IpAddress::sortKey('::ffff:1.2.3.4'));
        $this->assertLessThan(IpAddress::sortKey('0.0.0.0'), IpAddress::sortKey('::1'));
        $this->assertLessThan(IpAddress::sortKey('::1:0:0:0'), IpAddress::sortKey('255.255.255.255'));
        $this->assertNull(IpAddress::sortKey('nonsense'));
    }
}
