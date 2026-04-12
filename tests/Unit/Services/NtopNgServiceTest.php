<?php

namespace Tests\Unit\Services;

use App\Services\NtopNgService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use ReflectionClass;
use Tests\TestCase;

class NtopNgServiceTest extends TestCase
{
    public function test_get_stats_returns_decoded_response(): void
    {
        $responseBody = json_encode(['rsp' => ['bytes.rcvd' => 1000, 'bytes.sent' => 2000]]);
        $mock = new MockHandler([
            new Response(200, [], $responseBody),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $service = new NtopNgService('http://localhost', 'user', 'pass', 1);

        $reflection = new ReflectionClass($service);
        $prop = $reflection->getProperty('client');
        $prop->setValue($service, $client);

        $result = $service->getStats('10.0.0.1');
        $this->assertEquals(1000, $result->rsp->{'bytes.rcvd'});
        $this->assertEquals(2000, $result->rsp->{'bytes.sent'});
    }
}
