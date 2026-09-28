<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\DhcpLease;
use App\Models\IpAddress;
use App\Models\MacAddress;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DhcpLeaseIntegrationColumnTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_dhcp_lease_can_have_integration(): void
    {
        $lease = DhcpLease::factory()->create(['integration' => 'cisco']);

        $this->assertSame('cisco', $lease->integration);
    }

    public function test_dhcp_lease_integration_is_nullable(): void
    {
        $lease = DhcpLease::factory()->create(['integration' => null]);

        $this->assertNull($lease->integration);
    }

    public function test_unique_constraint_on_ip_and_mac(): void
    {
        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();

        DhcpLease::factory()->create([
            'integration' => 'cisco',
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
        ]);

        $this->expectException(QueryException::class);

        DhcpLease::factory()->create([
            'integration' => 'cisco',
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
        ]);
    }

    public function test_different_integrations_can_have_same_ip_and_mac(): void
    {
        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();

        DhcpLease::factory()->create([
            'integration' => 'cisco',
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
        ]);

        $this->expectException(QueryException::class);

        // Identity is (ip_address_id, mac_address_id); integration is only
        // a record of which integration reported the lease, so a second row
        // for the same ip+mac still collides even under a different integration.
        DhcpLease::factory()->create([
            'integration' => 'vyos',
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
        ]);
    }

    public function test_different_macs_can_have_same_ip(): void
    {
        $ip = IpAddress::factory()->create();

        DhcpLease::factory()->create([
            'integration' => 'cisco',
            'ip_address_id' => $ip->id,
        ]);

        DhcpLease::factory()->create([
            'integration' => 'cisco',
            'ip_address_id' => $ip->id,
        ]);

        $this->assertDatabaseCount('dhcp_leases', 2);
    }
}
