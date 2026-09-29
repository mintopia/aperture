<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Integration;

use App\Services\Integration\LibreNmsTester;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LibreNmsTesterTest extends TestCase
{
    private LibreNmsTester $tester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tester = new LibreNmsTester;
    }

    public function test_returns_success_on_200_response(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        $result = $this->tester->connect([
            'endpoint' => 'https://librenms.local',
            'api_key' => 'test-api-key',
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('Connected successfully', $result->message);
        $this->assertSame('GET', $result->requestMethod);
        $this->assertStringContainsString('/api/v0', $result->requestUrl);
        $this->assertSame(200, $result->responseStatus);
    }

    public function test_returns_failure_on_401_response(): void
    {
        Http::fake(['*' => Http::response('Unauthorized', 401)]);

        $result = $this->tester->connect([
            'endpoint' => 'https://librenms.local',
            'api_key' => 'bad-key',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Connection failed:', $result->message);
    }

    public function test_sends_x_auth_token_header(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->tester->connect([
            'endpoint' => 'https://librenms.local',
            'api_key' => 'my-librenms-token',
        ]);

        Http::assertSent(function ($request): bool {
            $token = $request->header('X-Auth-Token');

            return ! empty($token) && $token[0] === 'my-librenms-token';
        });
    }

    public function test_trims_trailing_slash_from_endpoint(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->tester->connect(['endpoint' => 'https://librenms.local/']);

        Http::assertSent(fn ($req): bool => str_contains($req->url(), 'https://librenms.local/api/v0'));
    }

    public function test_uses_endpoint_from_config(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->tester->connect(['endpoint' => 'https://my-librenms.example.com']);

        Http::assertSent(fn ($req): bool => str_contains($req->url(), 'my-librenms.example.com'));
    }

    public function test_non_json_success_response_is_a_failure(): void
    {
        Http::fake(['*' => Http::response('<html><body>Login</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);

        $result = $this->tester->connect(['endpoint' => 'https://x.local']);

        $this->assertFalse($result->success);
        $this->assertSame('LibreNMS returned a non-JSON response (text/html) — is a captive portal or proxy intercepting requests?', $result->message);
        $this->assertSame(200, $result->responseStatus);
    }

    public function test_expected_json_response_is_a_success(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        $result = $this->tester->connect(['endpoint' => 'https://x.local']);

        $this->assertTrue($result->success);
    }
}
