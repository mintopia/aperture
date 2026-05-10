<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch;

use App\Models\MacAddress;
use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Models\SwitchPortMac;
use App\Services\NetworkSwitch\PortMacSync;
use App\Services\ValueObjects\ForwardingEntry;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PortMacSyncTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creates_mac_entry_for_known_port(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'Gi1/0/1',
            'switchport_mode' => 'access',
        ]);

        $sync = new PortMacSync();
        $macEntries = collect([
            new ForwardingEntry(mac: 'aabb.ccdd.ee01', port: 'Gi1/0/1', vlan: 100),
        ]);

        $result = $sync->sync($macEntries, $switchConfig, Carbon::now());

        $this->assertDatabaseHas('switch_port_macs', [
            'switch_port_id' => $port->id,
            'mac_address' => 'AA:BB:CC:DD:EE:01',
            'vlan' => 100,
        ]);
        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertCount(1, $result['syncedMacIds']);
    }

    public function test_updates_existing_mac_entry_last_seen_at(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'Gi1/0/1',
            'switchport_mode' => 'access',
        ]);

        $existingMac = SwitchPortMac::factory()->create([
            'switch_port_id' => $port->id,
            'mac_address' => 'aabb.ccdd.ee01',
            'vlan' => 100,
            'last_seen_at' => now()->subDay(),
        ]);

        $originalLastSeen = $existingMac->last_seen_at;

        $sync = new PortMacSync();
        $macEntries = collect([
            new ForwardingEntry(mac: 'aabb.ccdd.ee01', port: 'Gi1/0/1', vlan: 100),
        ]);

        $result = $sync->sync($macEntries, $switchConfig, Carbon::now());

        $existingMac->refresh();
        $this->assertTrue($existingMac->last_seen_at->greaterThan($originalLastSeen));
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
    }

    public function test_skips_mac_entries_for_trunk_ports(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'Gi1/0/1',
            'switchport_mode' => 'trunk',
        ]);

        $sync = new PortMacSync();
        $macEntries = collect([
            new ForwardingEntry(mac: 'aabb.ccdd.ee01', port: 'Gi1/0/1', vlan: 100),
        ]);

        $result = $sync->sync($macEntries, $switchConfig, Carbon::now());

        $this->assertDatabaseCount('switch_port_macs', 0);
        $this->assertSame(0, $result['created']);
    }

    public function test_skips_mac_entries_for_unknown_port(): void
    {
        $switchConfig = SwitchConfig::factory()->create();

        $sync = new PortMacSync();
        $macEntries = collect([
            new ForwardingEntry(mac: 'aabb.ccdd.ee01', port: 'Gi1/0/99', vlan: 100),
        ]);

        $result = $sync->sync($macEntries, $switchConfig, Carbon::now());

        $this->assertDatabaseCount('switch_port_macs', 0);
        $this->assertSame(0, $result['created']);
    }

    public function test_clean_stale_macs_removes_entries_not_in_synced_ids(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'Gi1/0/1',
            'switchport_mode' => 'access',
        ]);

        $staleMac = SwitchPortMac::factory()->create([
            'switch_port_id' => $port->id,
            'mac_address' => 'dead.beef.0001',
            'vlan' => 100,
        ]);

        $sync = new PortMacSync();
        $sync->cleanStaleMacs($switchConfig, []);

        $this->assertDatabaseMissing('switch_port_macs', ['id' => $staleMac->id]);
    }

    public function test_clean_stale_macs_preserves_entries_in_synced_ids(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'Gi1/0/1',
            'switchport_mode' => 'access',
        ]);

        $keptMac = SwitchPortMac::factory()->create([
            'switch_port_id' => $port->id,
            'mac_address' => 'aabb.ccdd.ee01',
            'vlan' => 100,
        ]);

        $sync = new PortMacSync();
        $sync->cleanStaleMacs($switchConfig, [(int) $keptMac->id]);

        $this->assertDatabaseHas('switch_port_macs', ['id' => $keptMac->id]);
    }

    public function test_sync_creates_mac_address_record(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'Gi1/0/1',
            'switchport_mode' => 'access',
        ]);

        $sync = new PortMacSync();
        $macEntries = collect([
            new ForwardingEntry(mac: 'aabb.ccdd.ee01', port: 'Gi1/0/1', vlan: 100),
        ]);

        $sync->sync($macEntries, $switchConfig, Carbon::now());

        $this->assertDatabaseHas('mac_addresses', [
            'mac_address' => 'AA:BB:CC:DD:EE:01',
        ]);
    }
}
