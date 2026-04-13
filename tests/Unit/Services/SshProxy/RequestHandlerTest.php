<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SshProxy;

use App\Services\SshProxy\CommandExecutor;
use App\Services\SshProxy\RequestHandler;
use App\Services\SshProxy\SshConnection;
use App\Services\SshProxy\SshConnectionPool;
use Mockery;
use phpseclib3\Net\SSH2;
use Tests\TestCase;

class RequestHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function makeHandler(
        ?SshConnectionPool $pool = null,
        ?CommandExecutor $executor = null,
        string $apiKey = 'test-key',
    ): RequestHandler {
        $pool = $pool ?? Mockery::mock(SshConnectionPool::class);
        $executor = $executor ?? Mockery::mock(CommandExecutor::class);

        return new RequestHandler($pool, $executor, $apiKey);
    }

    public function test_execute_with_valid_auth_returns_success(): void
    {
        $mockSsh = Mockery::mock(SSH2::class);
        $conn = Mockery::mock(SshConnection::class);
        $conn->shouldReceive('lock')->once();
        $conn->shouldReceive('touch')->once();
        $conn->shouldReceive('unlock')->once();
        $conn->shouldReceive('getSsh')->andReturn($mockSsh);

        $pool = Mockery::mock(SshConnectionPool::class);
        $pool->shouldReceive('isLocked')->with('192.168.1.1')->andReturn(false);
        $pool->shouldReceive('get')->with('192.168.1.1')->andReturn($conn);

        $executor = Mockery::mock(CommandExecutor::class);
        $executor->shouldReceive('execute')->with($mockSsh, [['command' => 'show ver']])->andReturn([
            'success' => true,
            'output' => [['command' => 'show ver', 'output' => 'Cisco IOS']],
        ]);

        $handler = $this->makeHandler($pool, $executor);
        $result = $handler->handle('POST', '/execute', ['authorization' => 'Bearer test-key'], json_encode([
            'hostname' => '192.168.1.1',
            'username' => 'admin',
            'password' => 'secret',
            'commands' => [['command' => 'show ver']],
        ]));

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['body']['success']);
    }

    public function test_execute_with_invalid_auth_returns_401(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handle('POST', '/execute', ['authorization' => 'Bearer wrong-key'], '{}');

        $this->assertSame(401, $result['status']);
        $this->assertSame('Unauthorized', $result['body']['error']);
    }

    public function test_execute_with_missing_fields_returns_400(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handle('POST', '/execute', ['authorization' => 'Bearer test-key'], json_encode([
            'username' => 'admin',
            'commands' => [['command' => 'show ver']],
        ]));

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('Missing required fields', $result['body']['error']);
    }

    public function test_execute_with_locked_host_returns_409(): void
    {
        $pool = Mockery::mock(SshConnectionPool::class);
        $pool->shouldReceive('isLocked')->with('192.168.1.1')->andReturn(true);

        $handler = $this->makeHandler($pool);
        $result = $handler->handle('POST', '/execute', ['authorization' => 'Bearer test-key'], json_encode([
            'hostname' => '192.168.1.1',
            'username' => 'admin',
            'password' => 'secret',
            'commands' => [['command' => 'show ver']],
        ]));

        $this->assertSame(409, $result['status']);
        $this->assertStringContainsString('locked', $result['body']['error']);
    }

    public function test_execute_creates_new_connection_for_unknown_host(): void
    {
        $mockSsh = Mockery::mock(SSH2::class);

        $pool = Mockery::mock(SshConnectionPool::class);
        $pool->shouldReceive('isLocked')->with('192.168.1.1')->andReturn(false);
        $pool->shouldReceive('get')->with('192.168.1.1')->andReturn(null);
        $pool->shouldReceive('put')->with('192.168.1.1', Mockery::type(SshConnection::class))->once();

        $executor = Mockery::mock(CommandExecutor::class);
        $executor->shouldReceive('execute')->with($mockSsh, Mockery::any())->andReturn(['success' => true, 'output' => []]);

        $handler = Mockery::mock(RequestHandler::class, [$pool, $executor, 'test-key'])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $handler->shouldReceive('createSshConnection')
            ->with('192.168.1.1', 'admin', 'secret')
            ->andReturn($mockSsh);

        $result = $handler->handle('POST', '/execute', ['authorization' => 'Bearer test-key'], json_encode([
            'hostname' => '192.168.1.1',
            'username' => 'admin',
            'password' => 'secret',
            'commands' => [['command' => 'show ver']],
        ]));

        $this->assertSame(200, $result['status']);
    }

    public function test_execute_reuses_existing_connection(): void
    {
        $mockSsh = Mockery::mock(SSH2::class);
        $conn = Mockery::mock(SshConnection::class);
        $conn->shouldReceive('lock')->once();
        $conn->shouldReceive('touch')->once();
        $conn->shouldReceive('unlock')->once();
        $conn->shouldReceive('getSsh')->andReturn($mockSsh);

        $pool = Mockery::mock(SshConnectionPool::class);
        $pool->shouldReceive('isLocked')->with('192.168.1.1')->andReturn(false);
        $pool->shouldReceive('get')->with('192.168.1.1')->andReturn($conn);
        $pool->shouldNotReceive('put');

        $executor = Mockery::mock(CommandExecutor::class);
        $executor->shouldReceive('execute')->with($mockSsh, Mockery::any())->andReturn([
            'success' => true,
            'output' => [],
        ]);

        $handler = $this->makeHandler($pool, $executor);
        $result = $handler->handle('POST', '/execute', ['authorization' => 'Bearer test-key'], json_encode([
            'hostname' => '192.168.1.1',
            'username' => 'admin',
            'password' => 'secret',
            'commands' => [['command' => 'show ver']],
        ]));

        $this->assertSame(200, $result['status']);
    }

    public function test_execute_locks_and_unlocks_during_execution(): void
    {
        $mockSsh = Mockery::mock(SSH2::class);
        $conn = Mockery::mock(SshConnection::class);
        $conn->shouldReceive('lock')->once()->ordered();
        $conn->shouldReceive('touch')->once()->ordered();
        $conn->shouldReceive('getSsh')->andReturn($mockSsh);
        $conn->shouldReceive('unlock')->once()->ordered();

        $pool = Mockery::mock(SshConnectionPool::class);
        $pool->shouldReceive('isLocked')->with('192.168.1.1')->andReturn(false);
        $pool->shouldReceive('get')->with('192.168.1.1')->andReturn($conn);

        $executor = Mockery::mock(CommandExecutor::class);
        $executor->shouldReceive('execute')->andReturn(['success' => true, 'output' => []]);

        $handler = $this->makeHandler($pool, $executor);
        $result = $handler->handle('POST', '/execute', ['authorization' => 'Bearer test-key'], json_encode([
            'hostname' => '192.168.1.1',
            'username' => 'admin',
            'password' => 'secret',
            'commands' => [['command' => 'show ver']],
        ]));

        $this->assertSame(200, $result['status']);
    }

    public function test_status_returns_pool_status_with_uptime(): void
    {
        $pool = Mockery::mock(SshConnectionPool::class);
        $pool->shouldReceive('getStatus')->andReturn([
            ['hostname' => 'host1', 'created_at' => '2026-01-01T00:00:00+00:00', 'last_used_at' => '2026-01-01T00:00:00+00:00', 'locked' => false],
        ]);

        $handler = $this->makeHandler($pool);
        $result = $handler->handle('GET', '/status', ['authorization' => 'Bearer test-key'], '');

        $this->assertSame(200, $result['status']);
        $this->assertArrayHasKey('uptime_seconds', $result['body']);
        $this->assertArrayHasKey('connections', $result['body']);
        $this->assertIsInt($result['body']['uptime_seconds']);
        $this->assertCount(1, $result['body']['connections']);
    }

    public function test_status_with_invalid_auth_returns_401(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handle('GET', '/status', ['authorization' => 'Bearer wrong-key'], '');

        $this->assertSame(401, $result['status']);
    }

    public function test_unknown_route_returns_404(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handle('GET', '/unknown', ['authorization' => 'Bearer test-key'], '');

        $this->assertSame(404, $result['status']);
        $this->assertSame('Not found', $result['body']['error']);
    }

    public function test_execute_with_invalid_json_returns_400(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handle('POST', '/execute', ['authorization' => 'Bearer test-key'], 'not valid json{{{');

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('Invalid JSON', $result['body']['error']);
    }
}
