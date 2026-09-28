<?php

namespace Tests\Unit\Services;

use App\Services\Borealis\RequestException;
use App\Services\BorealisService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\RequestException as HttpRequestException;
use stdClass;
use Tests\Support\Fake;
use Tests\TestCase;

class BorealisServiceTest extends TestCase
{
    /**
     * @param  list<PromiseInterface|\Throwable>  $responses
     */
    protected function createServiceWithMockClient(array $responses): BorealisService
    {
        Fake::sequence($responses);

        return new BorealisService('client-id', 'client-secret', 'http://borealis.test');
    }

    public function test_check_returns_std_class(): void
    {
        $responseBody = json_encode([
            'user' => ['id' => '123', 'nickname' => 'test'],
            'access_token' => 'token',
        ]);

        $service = $this->createServiceWithMockClient([
            Fake::response(200, [], $responseBody),
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
            Fake::response(200, [], $responseBody),
        ]);

        $result = $service->getDeviceCodeRaw('test-scope');
        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertEquals('abc123', $result->device_code);
    }

    public function test_make_request_throws_request_exception_on403(): void
    {
        $service = $this->createServiceWithMockClient([
            Fake::response(403, [], (string) json_encode(['error' => 'authorization_pending'])),
        ]);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('authorization_pending');
        $service->check('invalid');
    }

    public function test_make_request_rethrows_non403_http_error(): void
    {
        $service = $this->createServiceWithMockClient([
            Fake::response(404, [], 'Not Found'),
        ]);

        $this->expectException(HttpRequestException::class);
        $service->check('some-code');
    }

    public function test_request_sends_form_credentials_to_endpoint(): void
    {
        $service = $this->createServiceWithMockClient([Fake::response(200, [], '{}')]);

        $service->check('dc');

        $request = Fake::requests()[0];
        $this->assertSame('http://borealis.test/oauth2/token', $request->url());
        $this->assertSame('client-id', $request->data()['client_id']);
        $this->assertSame('dc', $request->data()['device_code']);
    }

    public function test_decode_response_throws_on_invalid_json(): void
    {
        $service = $this->createServiceWithMockClient([
            Fake::response(200, [], 'not-valid-json{{{'),
        ]);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Unable to decode response');
        $service->check('some-code');
    }
}
