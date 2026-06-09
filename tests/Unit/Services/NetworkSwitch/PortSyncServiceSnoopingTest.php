<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch;

use App\Models\DhcpSnoopingObservation;
use App\Models\SwitchConfig;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\Interfaces\SupportsDhcpSnooping;
use App\Services\NetworkSwitch\PortConfigSync;
use App\Services\NetworkSwitch\PortMacSync;
use App\Services\NetworkSwitch\PortStatusSync;
use App\Services\NetworkSwitch\PortSyncService;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use App\Services\NetworkSwitch\SyncRunTracker;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class PortSyncServiceSnoopingTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @var MockInterface&NetworkSwitchInterface&SupportsDhcpSnooping */
    private MockInterface $snoopingAdapter;

    /** @var MockInterface&NetworkSwitchInterface */
    private MockInterface $plainAdapter;

    private MockInterface&SwitchServiceFactory $factory;

    private PortSyncService $service;

    private SwitchConfig $switchConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->switchConfig = SwitchConfig::factory()->create();

        $this->snoopingAdapter = Mockery::mock(NetworkSwitchInterface::class, SupportsDhcpSnooping::class);
        $this->plainAdapter = Mockery::mock(NetworkSwitchInterface::class);

        $this->factory = Mockery::mock(SwitchServiceFactory::class);

        // Default: snooping adapter for all make() calls
        $this->factory->shouldReceive('make')
            ->andReturn($this->snoopingAdapter)
            ->byDefault();

        // Stub the network calls needed by the base sync flow
        foreach ([$this->snoopingAdapter, $this->plainAdapter] as $adapter) {
            $adapter->shouldReceive('getAllPorts')->andReturn(collect())->byDefault();
            $adapter->shouldReceive('getForwardingDatabase')->andReturn(collect())->byDefault();
        }

        $this->service = new PortSyncService(
            $this->factory,
            new SyncRunTracker,
            new PortStatusSync,
            new PortMacSync,
            new PortConfigSync,
        );
    }

    public function test_processes_snooping_bindings_when_adapter_supports_it(): void
    {
        $bindings = collect([
            ['ip' => '10.0.0.50', 'mac' => '00:11:22:33:44:55', 'vlan' => 100, 'interface' => 'GigabitEthernet1/0/1', 'lease_seconds' => 86400],
            ['ip' => '10.0.0.51', 'mac' => 'AA:BB:CC:DD:EE:FF', 'vlan' => 200, 'interface' => 'GigabitEthernet1/0/2', 'lease_seconds' => 3600],
        ]);

        $this->snoopingAdapter->shouldReceive('getDhcpSnoopingBindings')
            ->once()
            ->andReturn($bindings);

        $this->service->syncSwitch($this->switchConfig);

        $this->assertDatabaseHas('dhcp_snooping_observations', [
            'switch_config_id' => $this->switchConfig->id,
            'ip' => '10.0.0.50',
            'mac' => '00:11:22:33:44:55',
            'vlan' => 100,
            'interface' => 'GigabitEthernet1/0/1',
        ]);

        $this->assertDatabaseHas('dhcp_snooping_observations', [
            'switch_config_id' => $this->switchConfig->id,
            'ip' => '10.0.0.51',
            'mac' => 'AA:BB:CC:DD:EE:FF',
            'vlan' => 200,
            'interface' => 'GigabitEthernet1/0/2',
        ]);

        $this->assertSame(2, DhcpSnoopingObservation::where('switch_config_id', $this->switchConfig->id)->count());
    }

    public function test_skips_snooping_when_adapter_does_not_support_it(): void
    {
        $this->factory->shouldReceive('make')->andReturn($this->plainAdapter);

        $this->service->syncSwitch($this->switchConfig);

        $this->assertSame(0, DhcpSnoopingObservation::where('switch_config_id', $this->switchConfig->id)->count());
    }

    public function test_deletes_stale_snooping_observations(): void
    {
        // Pre-existing observations for this switch
        $stale1 = DhcpSnoopingObservation::factory()->create([
            'switch_config_id' => $this->switchConfig->id,
            'ip' => '10.0.0.99',
            'mac' => 'DE:AD:BE:EF:00:01',
            'vlan' => 100,
        ]);
        $stale2 = DhcpSnoopingObservation::factory()->create([
            'switch_config_id' => $this->switchConfig->id,
            'ip' => '10.0.0.100',
            'mac' => 'DE:AD:BE:EF:00:02',
            'vlan' => 100,
        ]);

        // Only one binding returned this sync cycle
        $bindings = collect([
            ['ip' => '10.0.0.50', 'mac' => '00:11:22:33:44:55', 'vlan' => 100, 'interface' => 'GigabitEthernet1/0/1', 'lease_seconds' => 86400],
        ]);

        $this->snoopingAdapter->shouldReceive('getDhcpSnoopingBindings')
            ->once()
            ->andReturn($bindings);

        $this->service->syncSwitch($this->switchConfig);

        // Stale observations must be gone
        $this->assertDatabaseMissing('dhcp_snooping_observations', ['id' => $stale1->id]);
        $this->assertDatabaseMissing('dhcp_snooping_observations', ['id' => $stale2->id]);

        // Current binding should still exist
        $this->assertDatabaseHas('dhcp_snooping_observations', [
            'switch_config_id' => $this->switchConfig->id,
            'ip' => '10.0.0.50',
        ]);
    }

    public function test_sync_succeeds_when_snooping_throws(): void
    {
        $this->snoopingAdapter->shouldReceive('getDhcpSnoopingBindings')
            ->once()
            ->andThrow(new RuntimeException('DHCP snooping table not found'));

        $result = $this->service->syncSwitch($this->switchConfig);

        $this->assertSame('completed', $result->syncRun->status);
    }

    public function test_normalizes_mac_addresses(): void
    {
        // Raw/non-normalized MAC formats
        $bindings = collect([
            ['ip' => '10.0.0.10', 'mac' => '0011.2233.4455', 'vlan' => 10, 'interface' => 'Gi1/0/1', 'lease_seconds' => 3600],
        ]);

        $this->snoopingAdapter->shouldReceive('getDhcpSnoopingBindings')
            ->once()
            ->andReturn($bindings);

        $this->service->syncSwitch($this->switchConfig);

        $this->assertDatabaseHas('dhcp_snooping_observations', [
            'switch_config_id' => $this->switchConfig->id,
            'ip' => '10.0.0.10',
            'mac' => '00:11:22:33:44:55',
        ]);
    }
}
