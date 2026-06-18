<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Integration;

use App\Services\Integration\ConnectionTester;
use App\Services\ValueObjects\TestConnectionResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ConnectionTesterTest extends TestCase
{
    public function test_returns_success_result_on_200_response(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        $result = ConnectionTester::test(
            'GET',
            'https://example.com/api',
            fn () => Http::get('https://example.com/api'),
        );

        $this->assertTrue($result->success);
        $this->assertSame('Connected successfully', $result->message);
        $this->assertSame('GET', $result->requestMethod);
        $this->assertSame('https://example.com/api', $result->requestUrl);
        $this->assertSame(200, $result->responseStatus);
        $this->assertNotNull($result->output);
    }

    public function test_returns_failure_result_on_http_error(): void
    {
        Http::fake(['*' => Http::response('Internal Server Error', 500)]);

        $result = ConnectionTester::test(
            'GET',
            'https://example.com/api',
            fn () => Http::get('https://example.com/api'),
        );

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Connection failed:', $result->message);
        $this->assertSame('GET', $result->requestMethod);
        $this->assertSame('https://example.com/api', $result->requestUrl);
        $this->assertNull($result->responseStatus);
        $this->assertNull($result->output);
    }

    public function test_returns_failure_result_on_thrown_exception(): void
    {
        $result = ConnectionTester::test(
            'POST',
            'https://example.com/api',
            fn () => throw new RuntimeException('Connection refused'),
        );

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Connection failed: Connection refused', $result->message);
        $this->assertSame('POST', $result->requestMethod);
        $this->assertSame('https://example.com/api', $result->requestUrl);
    }

    public function test_uses_custom_success_message(): void
    {
        Http::fake(['*' => Http::response(['data' => 'ok'], 200)]);

        $result = ConnectionTester::test(
            'POST',
            'https://example.com/auth',
            fn () => Http::post('https://example.com/auth'),
            'Authenticated and received device code.',
        );

        $this->assertTrue($result->success);
        $this->assertSame('Authenticated and received device code.', $result->message);
    }

    public function test_populates_response_body_on_success(): void
    {
        Http::fake(['*' => Http::response('raw body text', 200)]);

        $result = ConnectionTester::test(
            'GET',
            'https://example.com/status',
            fn () => Http::get('https://example.com/status'),
        );

        $this->assertTrue($result->success);
        $this->assertSame('raw body text', $result->responseBody);
    }

    public function test_output_falls_back_to_body_when_not_json(): void
    {
        Http::fake(['*' => Http::response('plain text', 200)]);

        $result = ConnectionTester::test(
            'GET',
            'https://example.com/text',
            fn () => Http::get('https://example.com/text'),
        );

        $this->assertSame('plain text', $result->output);
    }

    public function test_output_uses_json_when_response_is_json(): void
    {
        Http::fake(['*' => Http::response(['key' => 'value'], 200)]);

        $result = ConnectionTester::test(
            'GET',
            'https://example.com/json',
            fn () => Http::get('https://example.com/json'),
        );

        $this->assertIsArray($result->output);
        $this->assertSame('value', $result->output['key']);
    }

    public function test_result_is_testconnectionresult_instance(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $result = ConnectionTester::test(
            'GET',
            'https://example.com',
            fn () => Http::get('https://example.com'),
        );

        $this->assertInstanceOf(TestConnectionResult::class, $result);
    }
}
