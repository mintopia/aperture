<?php

namespace Tests\Unit\Services;

use App\Services\Borealis\RequestException;
use App\Services\BorealisService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use ReflectionClass;
use stdClass;
use Tests\TestCase;

class BorealisServiceTest extends TestCase
{
    protected function createServiceWithMockClient(array $responses): BorealisService
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $service = new BorealisService('client-id', 'client-secret', 'http://localhost');

        $reflection = new ReflectionClass($service);
        $prop = $reflection->getProperty('client');
        $prop->setValue($service, $client);

        return $service;
    }

    public function test_check_returns_std_class(): void
    {
        $responseBody = json_encode([
            'user' => ['id' => '123', 'nickname' => 'test'],
            'access_token' => 'token',
        ]);

        $service = $this->createServiceWithMockClient([
            new Response(200, [], $responseBody),
        ]);

        $result = $service->check('device-code-123');
        $this->assertInstanceOf(stdClass::class, $result);
    }

    public function test_get_device_code_raw_returns_std_class(): void
    {
        $responseBody = json_encode([
            'device_code' => 'abc123',
            'user_code' => 'CODE',
            'interval' => 5,
            'verification_uri' => 'https://example.com',
            'verification_uri_complete' => 'https://example.com?code=CODE',
            'expires_in' => 600,
        ]);

        $service = $this->createServiceWithMockClient([
            new Response(200, [], $responseBody),
        ]);

        $result = $service->getDeviceCodeRaw('test-scope');
        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertEquals('abc123', $result->device_code);
    }

    public function test_get_user_with_token_returns_user(): void
    {
        $responseBody = json_encode(['id' => '123', 'nickname' => 'testuser', 'email' => 'test@example.com']);

        $service = $this->createServiceWithMockClient([
            new Response(200, [], $responseBody),
        ]);

        $result = $service->getUserWithToken('bearer-token');
        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertEquals('testuser', $result->nickname);
    }

    public function test_make_request_throws_request_exception_on403(): void
    {
        $responseBody = json_encode(['error' => 'authorization_pending']);

        $mock = new MockHandler([
            new ClientException(
                'Forbidden',
                new Request('POST', '/oauth2/token'),
                new Response(403, [], $responseBody)
            ),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $service = new BorealisService('client-id', 'client-secret', 'http://localhost');
        $reflection = new ReflectionClass($service);
        $prop = $reflection->getProperty('client');
        $prop->setValue($service, $client);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('authorization_pending');
        $service->check('invalid');
    }

    public function test_make_request_rethrows_non403_client_exception(): void
    {
        $mock = new MockHandler([
            new ClientException(
                'Not Found',
                new Request('POST', '/oauth2/token'),
                new Response(404, [], 'Not Found')
            ),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $service = new BorealisService('client-id', 'client-secret', 'http://localhost');
        $reflection = new ReflectionClass($service);
        $prop = $reflection->getProperty('client');
        $prop->setValue($service, $client);

        $this->expectException(ClientException::class);
        $service->check('some-code');
    }

    public function test_decode_response_throws_on_invalid_json(): void
    {
        $service = $this->createServiceWithMockClient([
            new Response(200, [], 'not-valid-json{{{'),
        ]);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Unable to decode response');
        $service->check('some-code');
    }
}
