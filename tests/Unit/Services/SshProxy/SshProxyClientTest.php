<?php

namespace Tests\Unit\Services\SshProxy;

use App\Services\Interfaces\SshProxyClientInterface;
use App\Services\SshProxy\CommandOutput;
use App\Services\SshProxy\CommandResult;
use App\Services\SshProxy\ConnectionStatus;
use App\Services\SshProxy\ProxyStatus;
use App\Services\SshProxy\SshProxyClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

class SshProxyClientTest extends TestCase
{
    protected function createClientWithMockHandler(array $responses): SshProxyClient
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $proxyClient = new SshProxyClient('http://localhost:8022', 'test-key');

        $reflection = new ReflectionClass($proxyClient);
        $prop = $reflection->getProperty('client');
        $prop->setValue($proxyClient, $client);

        return $proxyClient;
    }

    public function test_execute_sends_post_with_correct_payload(): void
    {
        $expectedResponse = [
            'success' => true,
            'output' => [
                ['command' => 'show version', 'output' => 'OPNsense 23.7'],
            ],
        ];

        $proxyClient = $this->createClientWithMockHandler([
            new Response(200, [], json_encode($expectedResponse)),
        ]);

        $result = $proxyClient->execute(
            '192.168.1.1',
            'admin',
            'password123',
            [['command' => 'show version']],
        );

        $this->assertInstanceOf(CommandResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertCount(1, $result->output);
        $this->assertInstanceOf(CommandOutput::class, $result->output[0]);
        $this->assertSame('show version', $result->output[0]->command);
        $this->assertSame('OPNsense 23.7', $result->output[0]->output);
    }

    public function test_execute_returns_parsed_json_response(): void
    {
        $responseData = [
            'success' => true,
            'output' => [
                ['command' => 'ifconfig', 'output' => 'em0: flags=8843'],
                ['command' => 'netstat -rn', 'output' => 'Routing tables'],
            ],
        ];

        $proxyClient = $this->createClientWithMockHandler([
            new Response(200, [], json_encode($responseData)),
        ]);

        $result = $proxyClient->execute(
            '10.0.0.1',
            'root',
            'secret',
            [
                ['command' => 'ifconfig'],
                ['command' => 'netstat -rn'],
            ],
        );

        $this->assertInstanceOf(CommandResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertCount(2, $result->output);
        $this->assertInstanceOf(CommandOutput::class, $result->output[0]);
        $this->assertInstanceOf(CommandOutput::class, $result->output[1]);
    }

    public function test_execute_throws_runtime_exception_on_409(): void
    {
        $proxyClient = $this->createClientWithMockHandler([
            new Response(409, [], json_encode(['error' => 'Host locked'])),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Host is currently locked by another request');

        $proxyClient->execute(
            '192.168.1.1',
            'admin',
            'password',
            [['command' => 'show version']],
        );
    }

    public function test_execute_throws_on_other_http_errors(): void
    {
        $proxyClient = $this->createClientWithMockHandler([
            new Response(500, [], json_encode(['error' => 'Internal server error'])),
        ]);

        $this->expectException(ServerException::class);

        $proxyClient->execute(
            '192.168.1.1',
            'admin',
            'password',
            [['command' => 'show version']],
        );
    }

    public function test_status_sends_get_request(): void
    {
        $statusResponse = [
            'uptime_seconds' => 3600,
            'connections' => [
                [
                    'hostname' => '192.168.1.1',
                    'connected_seconds' => 120,
                    'last_used_seconds_ago' => 5,
                    'locked' => false,
                ],
            ],
        ];

        $proxyClient = $this->createClientWithMockHandler([
            new Response(200, [], json_encode($statusResponse)),
        ]);

        $result = $proxyClient->status();

        $this->assertInstanceOf(ProxyStatus::class, $result);
        $this->assertSame(3600, $result->uptimeSeconds);
        $this->assertCount(1, $result->connections);
        $this->assertInstanceOf(ConnectionStatus::class, $result->connections[0]);
        $this->assertSame('192.168.1.1', $result->connections[0]->hostname);
        $this->assertFalse($result->connections[0]->locked);
    }

    public function test_status_returns_parsed_response(): void
    {
        $statusResponse = [
            'uptime_seconds' => 7200,
            'connections' => [
                [
                    'hostname' => '10.0.0.1',
                    'connected_seconds' => 300,
                    'last_used_seconds_ago' => 10,
                    'locked' => true,
                ],
                [
                    'hostname' => '10.0.0.2',
                    'connected_seconds' => 60,
                    'last_used_seconds_ago' => 2,
                    'locked' => false,
                ],
            ],
        ];

        $proxyClient = $this->createClientWithMockHandler([
            new Response(200, [], json_encode($statusResponse)),
        ]);

        $result = $proxyClient->status();

        $this->assertInstanceOf(ProxyStatus::class, $result);
        $this->assertSame(7200, $result->uptimeSeconds);
        $this->assertCount(2, $result->connections);
        $this->assertInstanceOf(ConnectionStatus::class, $result->connections[0]);
        $this->assertTrue($result->connections[0]->locked);
        $this->assertFalse($result->connections[1]->locked);
    }

    public function test_container_binding_resolves_correctly(): void
    {
        config([
            'aperture.ssh_proxy.host' => '127.0.0.1',
            'aperture.ssh_proxy.port' => 8022,
            'aperture.ssh_proxy.api_key' => 'test-api-key',
        ]);

        $resolved = $this->app->make(SshProxyClientInterface::class);

        $this->assertInstanceOf(SshProxyClient::class, $resolved);
    }

    public function test_guzzle_client_has_timeout_configured(): void
    {
        config([
            'aperture.ssh_proxy.host' => '127.0.0.1',
            'aperture.ssh_proxy.port' => 8022,
            'aperture.ssh_proxy.api_key' => 'test-api-key',
            'aperture.ssh_proxy.request_timeout' => 90,
            'aperture.ssh_proxy.connect_timeout' => 10,
        ]);

        // Clear the singleton so it gets re-resolved with new config
        $this->app->forgetInstance(SshProxyClientInterface::class);

        $resolved = $this->app->make(SshProxyClientInterface::class);

        $reflection = new ReflectionClass($resolved);
        $clientProp = $reflection->getProperty('client');
        /** @var Client $guzzleClient */
        $guzzleClient = $clientProp->getValue($resolved);

        $guzzleConfig = $guzzleClient->getConfig();

        $this->assertSame(90, $guzzleConfig['timeout']);
        $this->assertSame(10, $guzzleConfig['connect_timeout']);
    }

    public function test_guzzle_client_has_default_timeouts(): void
    {
        $proxyClient = new SshProxyClient('http://localhost:8022', 'test-key');

        $reflection = new ReflectionClass($proxyClient);
        $clientProp = $reflection->getProperty('client');
        /** @var Client $guzzleClient */
        $guzzleClient = $clientProp->getValue($proxyClient);

        $guzzleConfig = $guzzleClient->getConfig();

        $this->assertSame(60, $guzzleConfig['timeout']);
        $this->assertSame(5, $guzzleConfig['connect_timeout']);
    }

    public function test_execute_rethrows_non_409_client_exception(): void
    {
        $proxyClient = $this->createClientWithMockHandler([
            new Response(403, [], json_encode(['error' => 'Forbidden'])),
        ]);

        $this->expectException(ClientException::class);

        $proxyClient->execute(
            '192.168.1.1',
            'admin',
            'password',
            [['command' => 'show version']],
        );
    }

    public function test_invalid_configured_host_throws_actionable_laravel_exception(): void
    {
        config([
            'aperture.ssh_proxy.host' => 'http://bad host',
            'aperture.ssh_proxy.port' => 8022,
            'aperture.ssh_proxy.api_key' => 'test-api-key',
        ]);

        $this->app->forgetInstance(SshProxyClientInterface::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid SSH proxy host configuration [aperture.ssh_proxy.host]. Use a plain hostname or IP without scheme/path.');

        /** @var SshProxyClientInterface $resolved */
        $resolved = $this->app->make(SshProxyClientInterface::class);
        $resolved->status();
    }

    #[DataProvider('invalidSshProxyHostsProvider')]
    public function test_invalid_configured_host_with_special_characters_throws_actionable_laravel_exception(string $invalidHost): void
    {
        config([
            'aperture.ssh_proxy.host' => $invalidHost,
            'aperture.ssh_proxy.port' => 8022,
            'aperture.ssh_proxy.api_key' => 'test-api-key',
        ]);

        $this->app->forgetInstance(SshProxyClientInterface::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid SSH proxy host configuration [aperture.ssh_proxy.host]. Use a plain hostname or IP without scheme/path.');

        /** @var SshProxyClientInterface $resolved */
        $resolved = $this->app->make(SshProxyClientInterface::class);
        $resolved->status();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidSshProxyHostsProvider(): array
    {
        return [
            'contains userinfo separator' => ['switch-admin@10.0.0.5'],
            'contains query delimiter' => ['10.0.0.5?debug=1'],
            'contains fragment delimiter' => ['10.0.0.5#fragment'],
            'contains path segment' => ['10.0.0.5/path'],
            'contains scheme and userinfo style host' => ['http://user@10.0.0.5'],
        ];
    }
}
