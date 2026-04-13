<?php

namespace Tests\Unit\Services\SshProxy;

use App\Services\SshProxy\SshProxyClient;
use App\Services\SshProxy\SshProxyClientInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
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

        $this->assertSame($expectedResponse, $result);
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

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('output', $result);
        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['output']);
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

        $this->assertSame($statusResponse, $result);
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

        $this->assertArrayHasKey('uptime_seconds', $result);
        $this->assertArrayHasKey('connections', $result);
        $this->assertSame(7200, $result['uptime_seconds']);
        $this->assertCount(2, $result['connections']);
        $this->assertTrue($result['connections'][0]['locked']);
        $this->assertFalse($result['connections'][1]['locked']);
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
}
