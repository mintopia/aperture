<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch;

use App\Models\SwitchConfig;
use App\Models\SwitchSyncRun;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\Interfaces\SupportsDhcpSnooping;
use App\Services\NetworkSwitch\PortConfigSync;
use App\Services\NetworkSwitch\PortMacSync;
use App\Services\NetworkSwitch\PortStatusSync;
use App\Services\NetworkSwitch\PortSyncService;
use App\Services\NetworkSwitch\SnoopingFetchStatus;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use App\Services\NetworkSwitch\SwitchSyncFailedException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PortSyncServiceTransactionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_network_calls_happen_before_transaction_opens(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $callOrder = [];

        $adapter = $this->createStub(NetworkSwitchInterface::class);
        $adapter->method('getAllPorts')->willReturnCallback(function () use (&$callOrder): Collection {
            $callOrder[] = 'getAllPorts';

            return collect();
        });
        $adapter->method('getForwardingDatabase')->willReturnCallback(function () use (&$callOrder): Collection {
            $callOrder[] = 'getForwardingDatabase';

            return collect();
        });

        $db = DB::partialMock();
        $db->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(function (callable $callback) use (&$callOrder) {
                $callOrder[] = 'transaction_open';

                return $callback();
            });

        $factory = $this->createStub(SwitchServiceFactory::class);
        $factory->method('make')->willReturn($adapter);

        $service = new PortSyncService($factory, new PortStatusSync, new PortMacSync, new PortConfigSync);
        $service->syncSwitch($switchConfig);

        $transactionIndex = array_search('transaction_open', $callOrder, true);
        $portsIndex = array_search('getAllPorts', $callOrder, true);
        $macsIndex = array_search('getForwardingDatabase', $callOrder, true);

        $this->assertNotFalse($portsIndex, 'getAllPorts() was not called');
        $this->assertNotFalse($macsIndex, 'getForwardingDatabase() was not called');
        $this->assertNotFalse($transactionIndex, 'DB::transaction() was not called');

        $this->assertLessThan(
            $transactionIndex,
            $portsIndex,
            'getAllPorts() must be called before the DB transaction opens',
        );
        $this->assertLessThan(
            $transactionIndex,
            $macsIndex,
            'getForwardingDatabase() must be called before the DB transaction opens',
        );
    }

    public function test_no_switch_io_happens_at_transaction_depth_during_sync(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $baseline = DB::transactionLevel();
        $levels = [];

        $adapter = Mockery::mock(NetworkSwitchInterface::class, SupportsDhcpSnooping::class);
        $adapter->shouldReceive('getAllPorts')->andReturnUsing(function () use (&$levels): Collection {
            $levels['getAllPorts'] = DB::transactionLevel();

            return collect();
        });
        $adapter->shouldReceive('getForwardingDatabase')->andReturnUsing(function () use (&$levels): Collection {
            $levels['getForwardingDatabase'] = DB::transactionLevel();

            return collect();
        });
        $adapter->shouldReceive('getDhcpSnoopingBindings')->andReturnUsing(function () use (&$levels): Collection {
            $levels['getDhcpSnoopingBindings'] = DB::transactionLevel();

            return collect();
        });

        $factory = $this->createMock(SwitchServiceFactory::class);
        $factory->method('make')->willReturn($adapter);

        $service = new PortSyncService($factory, new PortStatusSync, new PortMacSync, new PortConfigSync);
        $service->syncSwitch($switchConfig);

        $this->assertSame(
            ['getAllPorts' => $baseline, 'getForwardingDatabase' => $baseline, 'getDhcpSnoopingBindings' => $baseline],
            $levels,
        );
    }

    public function test_fetch_snapshot_opens_no_transaction_and_reports_snooping_status(): void
    {
        $switchConfig = SwitchConfig::factory()->create();

        $plain = Mockery::mock(NetworkSwitchInterface::class);
        $plain->shouldReceive('getAllPorts')->andReturn(collect());
        $plain->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $failing = Mockery::mock(NetworkSwitchInterface::class, SupportsDhcpSnooping::class);
        $failing->shouldReceive('getAllPorts')->andReturn(collect());
        $failing->shouldReceive('getForwardingDatabase')->andReturn(collect());
        $failing->shouldReceive('getDhcpSnoopingBindings')->andThrow(new RuntimeException('boom'));

        $ok = Mockery::mock(NetworkSwitchInterface::class, SupportsDhcpSnooping::class);
        $ok->shouldReceive('getAllPorts')->andReturn(collect());
        $ok->shouldReceive('getForwardingDatabase')->andReturn(collect());
        $ok->shouldReceive('getDhcpSnoopingBindings')->andReturn(collect());

        $statuses = [];
        foreach ([$plain, $failing, $ok] as $adapter) {
            $factory = $this->createMock(SwitchServiceFactory::class);
            $factory->method('make')->willReturn($adapter);
            $service = new PortSyncService($factory, new PortStatusSync, new PortMacSync, new PortConfigSync);

            $statuses[] = $service->fetchSnapshot($switchConfig)->snooping->status;
        }

        $this->assertSame(
            [SnoopingFetchStatus::Unsupported, SnoopingFetchStatus::Failed, SnoopingFetchStatus::Fetched],
            $statuses,
        );
    }

    public function test_sync_failure_is_recorded_once_and_signalled_with_recorded_exception(): void
    {
        $switchConfig = SwitchConfig::factory()->create();

        $factory = $this->createMock(SwitchServiceFactory::class);
        $factory->method('make')->willThrowException(new RuntimeException('Connection refused'));

        $service = new PortSyncService($factory, new PortStatusSync, new PortMacSync, new PortConfigSync);

        try {
            $service->syncSwitch($switchConfig);
            $this->fail('Expected sync to throw');
        } catch (SwitchSyncFailedException $switchSyncFailedException) {
            $this->assertSame('Connection refused', $switchSyncFailedException->getMessage());
            $this->assertInstanceOf(RuntimeException::class, $switchSyncFailedException->getPrevious());
        }

        $this->assertSame(1, SwitchSyncRun::where('switch_config_id', $switchConfig->id)->where('status', 'failed')->count());
    }
}
