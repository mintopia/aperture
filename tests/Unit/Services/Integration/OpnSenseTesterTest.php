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
        Http::fake(['*' => Http::response(['time' => '2024-01-01'], 200)]);

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

        Http::assertSent(function ($request) {
            $auth = $request->header('Authorization');

            return ! empty($auth)
                && $auth[0] === 'Basic '.base64_encode('my-api-key:my-api-secret');
        });
    }

    public function test_uses_endpoint_from_config(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->tester->connect(['endpoint' => 'https://my-opnsense.example.com']);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'my-opnsense.example.com'));
    }

    public function test_trims_trailing_slash_from_endpoint(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->tester->connect(['endpoint' => 'https://opnsense.local/']);

        Http::assertSent(fn ($req) => str_contains(
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
}
