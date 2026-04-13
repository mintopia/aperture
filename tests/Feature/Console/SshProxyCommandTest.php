<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\SshProxyCommand;
use App\Services\SshProxy\CommandExecutor;
use App\Services\SshProxy\RequestHandler;
use App\Services\SshProxy\SshConnectionPool;
use Illuminate\Contracts\Console\Kernel;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

class SshProxyCommandTest extends TestCase
{
    public function test_command_is_registered(): void
    {
        $this->artisan('list')
            ->expectsOutputToContain('aperture:ssh-proxy')
            ->assertExitCode(0);
    }

    public function test_command_fails_when_disabled(): void
    {
        config(['aperture.ssh_proxy.enabled' => false]);

        $this->artisan('aperture:ssh-proxy')
            ->expectsOutputToContain('SSH proxy is not enabled')
            ->assertExitCode(1);
    }

    public function test_command_signature_correct(): void
    {
        $command = $this->app->make(Kernel::class)
            ->all()['aperture:ssh-proxy'] ?? null;

        $this->assertNotNull($command);
        $this->assertSame('aperture:ssh-proxy', $command->getName());
    }

    public function test_command_fails_when_server_cannot_bind(): void
    {
        config([
            'aperture.ssh_proxy.enabled' => true,
            'aperture.ssh_proxy.host' => '999.999.999.999',
            'aperture.ssh_proxy.port' => 99999,
            'aperture.ssh_proxy.api_key' => 'test-key',
            'aperture.ssh_proxy.idle_timeout_seconds' => 300,
            'aperture.ssh_proxy.sweep_interval_seconds' => 60,
            'aperture.ssh_proxy.command_timeout_seconds' => 30,
            'aperture.ssh_proxy.read_timeout_seconds' => 5,
        ]);

        $this->artisan('aperture:ssh-proxy')
            ->expectsOutputToContain('Failed to start server')
            ->assertExitCode(1);
    }

    public function test_handle_client_processes_get_request(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);

        $httpRequest = "GET /status HTTP/1.1\r\nAuthorization: Bearer test-key\r\n\r\n";
        fwrite($pair[0], $httpRequest);

        $pool = Mockery::mock(SshConnectionPool::class);
        $pool->shouldReceive('getStatus')->andReturn([]);

        $executor = Mockery::mock(CommandExecutor::class);
        $handler = new RequestHandler($pool, $executor, 'test-key');

        $command = new SshProxyCommand;
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('handleClient');
        $method->invoke($command, $pair[1], $handler);

        $response = stream_get_contents($pair[0]);
        fclose($pair[0]);

        $this->assertStringContainsString('HTTP/1.1 200', $response);
        $this->assertStringContainsString('uptime_seconds', $response);
    }

    public function test_handle_client_processes_post_with_content_length(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);

        $body = json_encode([
            'hostname' => '192.168.1.1',
            'username' => 'admin',
            'password' => 'secret',
            'commands' => [['command' => 'show ver']],
        ]);
        $httpRequest = "POST /execute HTTP/1.1\r\nAuthorization: Bearer wrong-key\r\nContent-Type: application/json\r\nContent-Length: ".strlen((string) $body)."\r\n\r\n".$body;
        fwrite($pair[0], $httpRequest);

        $pool = Mockery::mock(SshConnectionPool::class);
        $executor = Mockery::mock(CommandExecutor::class);
        $handler = new RequestHandler($pool, $executor, 'test-key');

        $command = new SshProxyCommand;
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('handleClient');
        $method->invoke($command, $pair[1], $handler);

        $response = stream_get_contents($pair[0]);
        fclose($pair[0]);

        $this->assertStringContainsString('HTTP/1.1 401', $response);
        $this->assertStringContainsString('Unauthorized', $response);
    }

    public function test_handle_client_parses_headers(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);

        $httpRequest = "GET /unknown HTTP/1.1\r\nAuthorization: Bearer test-key\r\nX-Custom: value\r\n\r\n";
        fwrite($pair[0], $httpRequest);

        $pool = Mockery::mock(SshConnectionPool::class);
        $executor = Mockery::mock(CommandExecutor::class);
        $handler = new RequestHandler($pool, $executor, 'test-key');

        $command = new SshProxyCommand;
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('handleClient');
        $method->invoke($command, $pair[1], $handler);

        $response = stream_get_contents($pair[0]);
        fclose($pair[0]);

        $this->assertStringContainsString('HTTP/1.1 404', $response);
        $this->assertStringContainsString('Not found', $response);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
