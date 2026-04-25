<?php

namespace Tests\Unit\Services;

use App\Services\NtopNgService;
use App\Services\ValueObjects\HostBytes;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use ReflectionClass;
use Tests\TestCase;

class NtopNgServiceTest extends TestCase
{
    private function createServiceWithMockClient(MockHandler $mock): NtopNgService
    {
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $service = new NtopNgService('http://localhost', 'user', 'pass', 1);

        $reflection = new ReflectionClass($service);
        $prop = $reflection->getProperty('client');
        $prop->setValue($service, $client);

        return $service;
    }

    public function test_get_raw_stats_returns_decoded_response(): void
    {
        $responseBody = json_encode(['rsp' => ['bytes.rcvd' => 1000, 'bytes.sent' => 2000]]);
        $service = $this->createServiceWithMockClient(new MockHandler([
            new Response(200, [], $responseBody),
        ]));

        $result = $service->getRawStats('10.0.0.1');
        $this->assertEquals(1000, $result->rsp->{'bytes.rcvd'});
        $this->assertEquals(2000, $result->rsp->{'bytes.sent'});
    }

    public function test_get_host_bytes_returns_host_bytes_value_object(): void
    {
        $responseBody = json_encode(['rsp' => ['bytes.rcvd' => 5000, 'bytes.sent' => 3000]]);
        $service = $this->createServiceWithMockClient(new MockHandler([
            new Response(200, [], $responseBody),
        ]));

        $result = $service->getHostBytes('10.0.0.1');

        $this->assertInstanceOf(HostBytes::class, $result);
        $this->assertEquals(5000, $result->received);
        $this->assertEquals(3000, $result->sent);
    }

    public function test_get_host_bytes_returns_null_on_exception(): void
    {
        $service = $this->createServiceWithMockClient(new MockHandler([
            new ConnectException('Connection refused', new Request('GET', '/')),
        ]));

        $result = $service->getHostBytes('10.0.0.1');

        $this->assertNull($result);
    }
}
