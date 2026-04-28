<?php

declare(strict_types=1);

namespace Tests\Unit\Services\OpnSense;

use App\Services\Firewalls\Exceptions\BackendException;
use App\Services\OpnSense\OpnSenseClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class OpnSenseClientTest extends TestCase
{
    public function test_get_returns_decoded_json(): void
    {
        $mock = new MockHandler([
            new Response(200, [], '{"status":"ok"}'),
        ]);
        $client = new OpnSenseClient(new Client(['handler' => HandlerStack::create($mock)]));

        $result = $client->get('/api/test');

        $this->assertSame('ok', $result->status);
    }

    public function test_post_returns_decoded_json(): void
    {
        $mock = new MockHandler([
            new Response(200, [], '{"result":"saved"}'),
        ]);
        $client = new OpnSenseClient(new Client(['handler' => HandlerStack::create($mock)]));

        $result = $client->post('/api/test', [], ['data' => 'value']);

        $this->assertSame('saved', $result->result);
    }

    public function test_invalid_json_throws_backend_exception(): void
    {
        $mock = new MockHandler([
            new Response(200, [], 'not json'),
        ]);
        $client = new OpnSenseClient(new Client(['handler' => HandlerStack::create($mock)]));

        $this->expectException(BackendException::class);
        $client->get('/api/test');
    }
}
