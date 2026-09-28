<?php

namespace Tests\Unit\Services;

use App\Services\Borealis\RequestException;
use App\Services\BorealisService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException as HttpRequestException;
use Illuminate\Support\Facades\Http;
use stdClass;
use Tests\TestCase;

class BorealisServiceTest extends TestCase
{
    protected function createServiceWithMockClient(array $responses): BorealisService
    {
        Http::fake(['*' => Http::sequence($responses)]);

        return new BorealisService('client-id', 'client-secret', 'http://localhost');
    }

    public function test_check_returns_std_class(): void
    {
        $responseBody = json_encode([
            'user' => ['id' => '123', 'nickname' => 'test'],
            'access_token' => 'token',
        ]);

        $service = $this->createServiceWithMockClient([
            Http::response($responseBody),
        ]);

        $result = $service->check('device-code-123');
        $this->assertInstanceOf(stdClass::class, $result);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost/oauth2/token'
            && $request->isForm()
            && $request['device_code'] === 'device-code-123'
            && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:device_code'
            && $request['client_id'] === 'client-id'
            && $request['client_secret'] === 'client-secret');
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
            Http::response($responseBody),
        ]);

        $result = $service->getDeviceCodeRaw('test-scope');
        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertEquals('abc123', $result->device_code);
    }

    public function test_make_request_throws_request_exception_on403(): void
    {
        $service = $this->createServiceWithMockClient([
            Http::response(['error' => 'authorization_pending'], 403),
        ]);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('authorization_pending');
        $service->check('invalid');
    }

    public function test_make_request_rethrows_non403_client_exception(): void
    {
        $service = $this->createServiceWithMockClient([
            Http::response('Not Found', 404),
        ]);

        $this->expectException(HttpRequestException::class);
        $service->check('some-code');
    }

    public function test_decode_response_throws_on_invalid_json(): void
    {
        $service = $this->createServiceWithMockClient([
            Http::response('not-valid-json{{{'),
        ]);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Unable to decode response');
        $service->check('some-code');
    }
}
