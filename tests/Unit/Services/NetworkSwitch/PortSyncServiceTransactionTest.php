<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch;

use App\Models\SwitchConfig;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\NetworkSwitch\PortMacSync;
use App\Services\NetworkSwitch\PortStatusSync;
use App\Services\NetworkSwitch\PortSyncService;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use App\Services\NetworkSwitch\SyncRunTracker;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortSyncServiceTransactionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_network_calls_happen_before_transaction_opens(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $callOrder = [];

        $adapter = $this->createMock(NetworkSwitchInterface::class);
        $adapter->method('getAllPorts')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 'getAllPorts';

            return collect();
        });
        $adapter->method('getForwardingDatabase')->willReturnCallback(function () use (&$callOrder) {
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

        $factory = $this->createMock(SwitchServiceFactory::class);
        $factory->method('make')->willReturn($adapter);

        $service = new PortSyncService($factory, new SyncRunTracker, new PortStatusSync, new PortMacSync);
        $service->syncSwitch($switchConfig);

        $transactionIndex = array_search('transaction_open', $callOrder);
        $portsIndex = array_search('getAllPorts', $callOrder);
        $macsIndex = array_search('getForwardingDatabase', $callOrder);

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
}
