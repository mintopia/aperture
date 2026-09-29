<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Integration;

use App\Services\Integration\OpnSenseTester;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpnSenseTesterTest extends TestCase
{
    private OpnSenseTester $tester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tester = new OpnSenseTester;
    }

    public function test_returns_success_on_200_response(): void
    {
        Http::fake(['*' => Http::response(['datetime' => '2024-01-01 00:00:00', 'uptime' => '1 day'], 200)]);

        $result = $this->tester->connect([
            'endpoint' => 'https://opnsense.local',
            'key' => 'test-key',
            'secret' => 'test-secret',
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('Connected and authenticated successfully', $result->message);
        $this->assertSame('GET', $result->requestMethod);
        $this->assertStringContainsString('/api/diagnostics/system/system_time', $result->requestUrl);
        $this->assertSame(200, $result->responseStatus);
    }

    public function test_returns_failure_on_401_response(): void
    {
        Http::fake(['*' => Http::response('Unauthorized', 401)]);

        $result = $this->tester->connect([
            'endpoint' => 'https://opnsense.local',
            'key' => 'bad-key',
            'secret' => 'bad-secret',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Connection failed:', $result->message);
        $this->assertNull($result->responseStatus);
    }

    public function test_sends_basic_auth_credentials(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->tester->connect([
            'endpoint' => 'https://opnsense.local',
            'key' => 'my-api-key',
            'secret' => 'my-api-secret',
        ]);

        Http::assertSent(function ($request): bool {
            $auth = $request->header('Authorization');

            return ! empty($auth)
                && $auth[0] === 'Basic '.base64_encode('my-api-key:my-api-secret');
        });
    }

    public function test_uses_endpoint_from_config(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->tester->connect(['endpoint' => 'https://my-opnsense.example.com']);

        Http::assertSent(fn ($req): bool => str_contains($req->url(), 'my-opnsense.example.com'));
    }

    public function test_trims_trailing_slash_from_endpoint(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->tester->connect(['endpoint' => 'https://opnsense.local/']);

        Http::assertSent(fn ($req): bool => str_contains(
            $req->url(),
            'https://opnsense.local/api/diagnostics/system/system_time'
        ));
    }

    public function test_returns_failure_on_connection_exception(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        $result = $this->tester->connect(['endpoint' => 'https://unreachable.local']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Connection failed:', $result->message);
    }

    public function test_non_json_success_response_is_a_failure(): void
    {
        Http::fake(['*' => Http::response('<html><body>Login</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);

        $result = $this->tester->connect(['endpoint' => 'https://x.local']);

        $this->assertFalse($result->success);
        $this->assertSame('OPNsense returned a non-JSON response (text/html) — is a captive portal or proxy intercepting requests?', $result->message);
        $this->assertSame(200, $result->responseStatus);
    }

    public function test_json_response_missing_expected_fields_is_a_failure(): void
    {
        Http::fake(['*' => Http::response(['unrelated' => true], 200)]);

        $result = $this->tester->connect(['endpoint' => 'https://x.local']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('OPNsense returned an unexpected response', $result->message);
    }

    public function test_expected_json_response_is_a_success(): void
    {
        Http::fake(['*' => Http::response(['datetime' => '2024-01-01 00:00:00', 'uptime' => '1 day'], 200)]);

        $result = $this->tester->connect(['endpoint' => 'https://x.local']);

        $this->assertTrue($result->success);
    }
}
