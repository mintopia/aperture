<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\DhcpLease;
use App\Models\IpAddress;
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

    public function test_unique_constraint_on_integration_and_ip(): void
    {
        $ip = IpAddress::factory()->create();

        DhcpLease::factory()->create([
            'integration' => 'cisco',
            'ip_address_id' => $ip->id,
        ]);

        $this->expectException(QueryException::class);

        DhcpLease::factory()->create([
            'integration' => 'cisco',
            'ip_address_id' => $ip->id,
        ]);
    }

    public function test_different_integrations_can_have_same_ip(): void
    {
        $ip = IpAddress::factory()->create();

        DhcpLease::factory()->create([
            'integration' => 'cisco',
            'ip_address_id' => $ip->id,
        ]);

        $lease2 = DhcpLease::factory()->create([
            'integration' => 'vyos',
            'ip_address_id' => $ip->id,
        ]);

        $this->assertDatabaseCount('dhcp_leases', 2);
    }
}
