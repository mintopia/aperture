<?php

namespace Tests\Unit\Models;

use App\Models\IpAddress;
use App\Models\MacAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IpAddressLnmsTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_mac_returns_value_when_attached_via_pivot(): void
    {
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
        $ip = IpAddress::factory()->create();
        $ip->macAddresses()->attach($mac, ['source' => 'auth', 'last_seen_at' => now()]);

        $this->assertSame('AA:BB:CC:DD:EE:FF', $ip->currentMac()?->mac_address);
    }

    public function test_current_mac_returns_null_when_no_mac_attached(): void
    {
        $ip = IpAddress::factory()->create();
        $this->assertNull($ip->currentMac());
    }
}
