<?php

namespace Tests\Unit\Models;

use App\Models\IpAddress;
use App\Models\MacAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IpAddressLnmsTest extends TestCase
{
    use RefreshDatabase;

    public function test_mac_returns_value_from_relationship(): void
    {
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
        $ip = IpAddress::factory()->create(['mac_address_id' => $mac->id]);

        $this->assertSame('AA:BB:CC:DD:EE:FF', $ip->mac);
    }

    public function test_mac_returns_null_when_no_relationship(): void
    {
        $ip = IpAddress::factory()->create(['mac_address_id' => null]);
        $this->assertNull($ip->mac);
    }
}
