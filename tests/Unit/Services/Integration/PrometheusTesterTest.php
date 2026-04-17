<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Integration;

use App\Services\Integration\PrometheusTester;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PrometheusTesterTest extends TestCase
{
    private PrometheusTester $tester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tester = new PrometheusTester;
    }

    public function test_returns_success_on_200_response(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['version' => '2.50.0']], 200)]);

        $result = $this->tester->connect([
            'endpoint' => 'https://prometheus.local:9090',
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('Connected successfully', $result->message);
        $this->assertSame('GET', $result->requestMethod);
        $this->assertStringContainsString('/api/v1/status/buildinfo', $result->requestUrl);
        $this->assertSame(200, $result->responseStatus);
    }

    public function test_returns_failure_on_401_response(): void
    {
        Http::fake(['*' => Http::response('Unauthorized', 401)]);

        $result = $this->tester->connect([
            'endpoint' => 'https://prometheus.local:9090',
            'bearer_token' => 'bad-token',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Connection failed:', $result->message);
    }

    public function test_sends_bearer_token_when_provided(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $this->tester->connect([
            'endpoint' => 'https://prometheus.local:9090',
            'bearer_token' => 'my-secret-token',
        ]);

        Http::assertSent(function ($request): bool {
            $auth = $request->header('Authorization');

            return ! empty($auth) && $auth[0] === 'Bearer my-secret-token';
        });
    }

    public function test_does_not_send_auth_header_without_token(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $this->tester->connect([
            'endpoint' => 'https://prometheus.local:9090',
        ]);

        Http::assertSent(function ($request): bool {
            $auth = $request->header('Authorization');

            return empty($auth) || $auth[0] === '';
        });
    }

    public function test_trims_trailing_slash_from_endpoint(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->tester->connect(['endpoint' => 'https://prometheus.local:9090/']);

        Http::assertSent(fn ($req): bool => str_contains($req->url(), 'https://prometheus.local:9090/api/v1/status/buildinfo'));
    }

    public function test_uses_endpoint_from_config(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->tester->connect(['endpoint' => 'https://my-prom.example.com']);

        Http::assertSent(fn ($req): bool => str_contains($req->url(), 'my-prom.example.com'));
    }

    public function test_handles_connection_timeout(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('Connection timed out')]);

        $result = $this->tester->connect([
            'endpoint' => 'https://prometheus.local:9090',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Connection failed:', $result->message);
    }
}
