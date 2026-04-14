<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SshProxy;

use App\Services\SshProxy\ConnectionStatus;
use App\Services\SshProxy\SshConnection;
use App\Services\SshProxy\SshConnectionPool;
use Carbon\CarbonImmutable;
use Mockery;
use Tests\TestCase;

class SshConnectionPoolTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_put_and_get_store_and_retrieve_connection(): void
    {
        $pool = new SshConnectionPool;
        $conn = Mockery::mock(SshConnection::class);

        $pool->put('host1', $conn);

        $this->assertSame($conn, $pool->get('host1'));
    }

    public function test_get_returns_null_for_unknown_hostname(): void
    {
        $pool = new SshConnectionPool;

        $this->assertNull($pool->get('unknown'));
    }

    public function test_remove_disconnects_and_removes_connection(): void
    {
        $pool = new SshConnectionPool;
        $conn = Mockery::mock(SshConnection::class);
        $conn->shouldReceive('disconnect')->once();

        $pool->put('host1', $conn);
        $pool->remove('host1');

        $this->assertNull($pool->get('host1'));
    }

    public function test_remove_does_nothing_for_unknown_hostname(): void
    {
        $pool = new SshConnectionPool;

        $pool->remove('unknown');

        $this->assertNull($pool->get('unknown'));
    }

    public function test_sweep_idle_removes_only_idle_connections(): void
    {
        $pool = new SshConnectionPool;

        $idleConn = Mockery::mock(SshConnection::class);
        $idleConn->shouldReceive('isLocked')->andReturn(false);
        $idleConn->shouldReceive('isIdle')->with(600)->andReturn(true);
        $idleConn->shouldReceive('disconnect')->once();

        $activeConn = Mockery::mock(SshConnection::class);
        $activeConn->shouldReceive('isLocked')->andReturn(false);
        $activeConn->shouldReceive('isIdle')->with(600)->andReturn(false);

        $pool->put('idle-host', $idleConn);
        $pool->put('active-host', $activeConn);

        $removed = $pool->sweepIdle();

        $this->assertSame(1, $removed);
        $this->assertNull($pool->get('idle-host'));
        $this->assertSame($activeConn, $pool->get('active-host'));
    }

    public function test_sweep_idle_does_not_remove_locked_connections(): void
    {
        $pool = new SshConnectionPool;

        $conn = Mockery::mock(SshConnection::class);
        $conn->shouldReceive('isLocked')->andReturn(true);

        $pool->put('host1', $conn);

        $removed = $pool->sweepIdle();

        $this->assertSame(0, $removed);
        $this->assertSame($conn, $pool->get('host1'));
    }

    public function test_sweep_idle_does_not_remove_non_idle_connections(): void
    {
        $pool = new SshConnectionPool;

        $conn = Mockery::mock(SshConnection::class);
        $conn->shouldReceive('isLocked')->andReturn(false);
        $conn->shouldReceive('isIdle')->with(600)->andReturn(false);

        $pool->put('host1', $conn);

        $removed = $pool->sweepIdle();

        $this->assertSame(0, $removed);
        $this->assertSame($conn, $pool->get('host1'));
    }

    public function test_get_status_returns_info_for_all_connections(): void
    {
        $pool = new SshConnectionPool;

        $now = CarbonImmutable::now();
        $earlier = $now->subMinutes(5);

        $conn1 = Mockery::mock(SshConnection::class);
        $conn1->shouldReceive('getCreatedAt')->andReturn($earlier);
        $conn1->shouldReceive('getLastUsedAt')->andReturn($now);
        $conn1->shouldReceive('isLocked')->andReturn(false);

        $conn2 = Mockery::mock(SshConnection::class);
        $conn2->shouldReceive('getCreatedAt')->andReturn($now);
        $conn2->shouldReceive('getLastUsedAt')->andReturn($now);
        $conn2->shouldReceive('isLocked')->andReturn(true);

        $pool->put('host1', $conn1);
        $pool->put('host2', $conn2);

        $status = $pool->getStatus();

        $this->assertCount(2, $status);

        $this->assertInstanceOf(ConnectionStatus::class, $status[0]);
        $this->assertSame('host1', $status[0]->hostname);
        $this->assertGreaterThanOrEqual(300, $status[0]->connectedSeconds);
        $this->assertSame(0, $status[0]->lastUsedSecondsAgo);
        $this->assertFalse($status[0]->locked);

        $this->assertInstanceOf(ConnectionStatus::class, $status[1]);
        $this->assertSame('host2', $status[1]->hostname);
        $this->assertSame(0, $status[1]->connectedSeconds);
        $this->assertSame(0, $status[1]->lastUsedSecondsAgo);
        $this->assertTrue($status[1]->locked);
    }

    public function test_disconnect_all_disconnects_and_clears(): void
    {
        $pool = new SshConnectionPool;

        $conn1 = Mockery::mock(SshConnection::class);
        $conn1->shouldReceive('disconnect')->once();

        $conn2 = Mockery::mock(SshConnection::class);
        $conn2->shouldReceive('disconnect')->once();

        $pool->put('host1', $conn1);
        $pool->put('host2', $conn2);

        $pool->disconnectAll();

        $this->assertNull($pool->get('host1'));
        $this->assertNull($pool->get('host2'));
    }

    public function test_is_locked_returns_connection_locked_state(): void
    {
        $pool = new SshConnectionPool;

        $lockedConn = Mockery::mock(SshConnection::class);
        $lockedConn->shouldReceive('isLocked')->andReturn(true);

        $unlockedConn = Mockery::mock(SshConnection::class);
        $unlockedConn->shouldReceive('isLocked')->andReturn(false);

        $pool->put('locked-host', $lockedConn);
        $this->assertTrue($pool->isLocked('locked-host'));

        $pool->put('unlocked-host', $unlockedConn);
        $this->assertFalse($pool->isLocked('unlocked-host'));
    }

    public function test_is_locked_returns_false_for_unknown_hostname(): void
    {
        $pool = new SshConnectionPool;

        $this->assertFalse($pool->isLocked('unknown'));
    }
}
