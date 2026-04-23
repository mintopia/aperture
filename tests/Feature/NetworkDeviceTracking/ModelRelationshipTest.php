<?php

declare(strict_types=1);

namespace Tests\Feature\NetworkDeviceTracking;

use App\Models\AuditLog;
use App\Models\DhcpLease;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\SwitchPort;
use App\Models\SwitchPortMac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ModelRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_ip_has_many_mac_addresses_via_pivot(): void
    {
        $ip = IpAddress::factory()->create();
        $mac1 = MacAddress::factory()->create();
        $mac2 = MacAddress::factory()->create();

        $ip->macAddresses()->attach($mac1, ['source' => 'dhcp', 'last_seen_at' => now()]);
        $ip->macAddresses()->attach($mac2, ['source' => 'arp', 'last_seen_at' => now()]);

        $this->assertCount(2, $ip->refresh()->macAddresses);
    }

    public function test_mac_has_many_ip_addresses_via_pivot(): void
    {
        $mac = MacAddress::factory()->create();
        $ip1 = IpAddress::factory()->create();
        $ip2 = IpAddress::factory()->create();

        $mac->ipAddresses()->attach($ip1, ['source' => 'dhcp', 'last_seen_at' => now()]);
        $mac->ipAddresses()->attach($ip2, ['source' => 'arp', 'last_seen_at' => now()]);

        $this->assertCount(2, $mac->refresh()->ipAddresses);
    }

    public function test_pivot_includes_source_and_last_seen_at(): void
    {
        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();

        $ip->macAddresses()->attach($mac, ['source' => 'dhcp', 'last_seen_at' => now()->subMinutes(5)]);

        $related = $ip->macAddresses()->first();
        $this->assertNotNull($related);
        $this->assertEquals('dhcp', $related->pivot->source);
        $this->assertInstanceOf(Carbon::class, $related->pivot->last_seen_at);
    }

    public function test_current_mac_returns_latest_by_last_seen_at(): void
    {
        $ip = IpAddress::factory()->create();
        $oldMac = MacAddress::factory()->create();
        $newMac = MacAddress::factory()->create();

        $ip->macAddresses()->attach($oldMac, ['source' => 'arp', 'last_seen_at' => now()->subHours(2)]);
        $ip->macAddresses()->attach($newMac, ['source' => 'dhcp', 'last_seen_at' => now()]);

        $current = $ip->currentMac();
        $this->assertNotNull($current);
        $this->assertTrue($current->is($newMac));
    }

    public function test_current_mac_returns_null_when_no_macs(): void
    {
        $ip = IpAddress::factory()->create();

        $this->assertNull($ip->currentMac());
    }

    public function test_current_ip_returns_latest_by_last_seen_at(): void
    {
        $mac = MacAddress::factory()->create();
        $oldIp = IpAddress::factory()->create();
        $newIp = IpAddress::factory()->create();

        $mac->ipAddresses()->attach($oldIp, ['source' => 'arp', 'last_seen_at' => now()->subHours(2)]);
        $mac->ipAddresses()->attach($newIp, ['source' => 'dhcp', 'last_seen_at' => now()]);

        $current = $mac->currentIp();
        $this->assertNotNull($current);
        $this->assertTrue($current->is($newIp));
    }

    public function test_current_ip_returns_null_when_no_ips(): void
    {
        $mac = MacAddress::factory()->create();

        $this->assertNull($mac->currentIp());
    }

    public function test_current_hostname_from_latest_dhcp_lease(): void
    {
        $mac = MacAddress::factory()->create();
        $oldIp = IpAddress::factory()->create();
        $newIp = IpAddress::factory()->create();

        DhcpLease::factory()->create([
            'mac_address_id' => $mac->id,
            'ip_address_id' => $oldIp->id,
            'hostname' => 'old-hostname',
            'created_at' => now()->subHour(),
        ]);
        DhcpLease::factory()->create([
            'mac_address_id' => $mac->id,
            'ip_address_id' => $newIp->id,
            'hostname' => 'new-hostname',
            'created_at' => now(),
        ]);

        $this->assertEquals('new-hostname', $mac->currentHostname());
    }

    public function test_current_hostname_returns_null_when_no_leases(): void
    {
        $mac = MacAddress::factory()->create();

        $this->assertNull($mac->currentHostname());
    }

    public function test_ip_has_many_dhcp_leases(): void
    {
        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();

        DhcpLease::factory()->create(['ip_address_id' => $ip->id, 'mac_address_id' => $mac->id]);

        $this->assertCount(1, $ip->refresh()->dhcpLeases);
    }

    public function test_mac_has_many_dhcp_leases(): void
    {
        $mac = MacAddress::factory()->create();
        $ip = IpAddress::factory()->create();

        DhcpLease::factory()->create(['mac_address_id' => $mac->id, 'ip_address_id' => $ip->id]);

        $this->assertCount(1, $mac->refresh()->dhcpLeases);
    }

    public function test_switch_port_mac_belongs_to_mac_address_record(): void
    {
        $mac = MacAddress::factory()->create();
        $switchPort = SwitchPort::factory()->create();
        $spm = SwitchPortMac::factory()->create([
            'switch_port_id' => $switchPort->id,
            'mac_address' => $mac->mac_address,
            'mac_address_id' => $mac->id,
        ]);

        $this->assertNotNull($spm->macAddressRecord);
        $this->assertTrue($spm->macAddressRecord->is($mac));
    }

    public function test_ip_audit_logs_morph_many(): void
    {
        $ip = IpAddress::factory()->create();
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'test');

        $this->assertCount(1, $ip->refresh()->auditLogs);
    }

    public function test_mac_audit_logs_morph_many(): void
    {
        $mac = MacAddress::factory()->create();
        AuditLog::record(action: 'mac.created', subject: $mac, process: 'test');

        $this->assertCount(1, $mac->refresh()->auditLogs);
    }

    public function test_mac_switch_ports_via_switch_port_macs(): void
    {
        $mac = MacAddress::factory()->create();
        $switchPort = SwitchPort::factory()->create();
        SwitchPortMac::factory()->create([
            'switch_port_id' => $switchPort->id,
            'mac_address' => $mac->mac_address,
            'mac_address_id' => $mac->id,
        ]);

        $this->assertCount(1, $mac->refresh()->switchPorts);
        $firstSwitchPort = $mac->switchPorts->first();
        $this->assertNotNull($firstSwitchPort);
        $this->assertTrue($firstSwitchPort->is($switchPort));
    }
}
