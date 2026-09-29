<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Integration;

use App\Services\Integration\PiHoleTester;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PiHoleTesterTest extends TestCase
{
    private PiHoleTester $tester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tester = new PiHoleTester;
    }

    public function test_returns_success_on_200_response(): void
    {
        Http::fake(['*' => Http::response(['session' => ['sid' => 'abc', 'validity' => 300]], 200)]);

        $result = $this->tester->connect([
            'endpoint' => 'https://pihole.local',
            'password' => 'test-pass',
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('Connected and authenticated successfully', $result->message);
        $this->assertSame('POST', $result->requestMethod);
        $this->assertStringContainsString('/api/auth', $result->requestUrl);
        $this->assertSame(200, $result->responseStatus);
    }

    public function test_returns_failure_on_401_response(): void
    {
        Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);

        $result = $this->tester->connect([
            'endpoint' => 'https://pihole.local',
            'password' => 'wrong-pass',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Connection failed:', $result->message);
    }

    public function test_sends_password_in_json_body(): void
    {
        Http::fake(['*' => Http::response(['session' => ['sid' => 'tok']], 200)]);

        $this->tester->connect([
            'endpoint' => 'https://pihole.local',
            'password' => 'my-secret-password',
        ]);

        Http::assertSent(function ($request): bool {
            $body = json_decode($request->body(), true);

            return isset($body['password']) && $body['password'] === 'my-secret-password';
        });
    }

    public function test_sends_json_content_type(): void
    {
        Http::fake(['*' => Http::response(['session' => ['sid' => 'tok']], 200)]);

        $this->tester->connect(['endpoint' => 'https://pihole.local']);

        Http::assertSent(function ($request): bool {
            $ct = $request->header('Content-Type')[0] ?? '';

            return str_contains($ct, 'application/json');
        });
    }

    public function test_trims_trailing_slash_from_endpoint(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->tester->connect(['endpoint' => 'https://pihole.local/']);

        Http::assertSent(fn ($req): bool => str_contains($req->url(), 'https://pihole.local/api/auth'));
    }
}
