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
}
