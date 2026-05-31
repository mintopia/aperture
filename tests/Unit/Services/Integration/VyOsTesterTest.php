<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Integration;

use App\Services\Integration\VyOsTester;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VyOsTesterTest extends TestCase
{
    private VyOsTester $tester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tester = new VyOsTester;
    }

    public function test_returns_success_on_200_response(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => 'VyOS 1.4.0'], 200)]);

        $result = $this->tester->connect([
            'endpoint' => 'https://vyos.local',
            'api_key' => 'test-key',
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('Connected and authenticated successfully', $result->message);
        $this->assertSame('POST', $result->requestMethod);
        $this->assertStringContainsString('/show', $result->requestUrl);
        $this->assertSame(200, $result->responseStatus);
    }

    public function test_returns_failure_on_401_response(): void
    {
        Http::fake(['*' => Http::response('Unauthorized', 401)]);

        $result = $this->tester->connect([
            'endpoint' => 'https://vyos.local',
            'api_key' => 'bad-key',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Connection failed:', $result->message);
        $this->assertNull($result->responseStatus);
    }

    public function test_sends_api_key_in_form_data(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => ''], 200)]);

        $this->tester->connect([
            'endpoint' => 'https://vyos.local',
            'api_key' => 'my-secret-key',
        ]);

        Http::assertSent(function ($request): bool {
            return $request->data()['key'] === 'my-secret-key'
                && json_decode($request->data()['data'], true) === ['op' => 'show', 'path' => ['version']];
        });
    }

    public function test_uses_endpoint_from_config(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => ''], 200)]);

        $this->tester->connect(['endpoint' => 'https://my-vyos.example.com']);

        Http::assertSent(fn ($req): bool => str_contains($req->url(), 'my-vyos.example.com'));
    }

    public function test_trims_trailing_slash_from_endpoint(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => ''], 200)]);

        $this->tester->connect(['endpoint' => 'https://vyos.local/']);

        Http::assertSent(fn ($req): bool => str_contains($req->url(), 'https://vyos.local/show'));
    }

    public function test_returns_failure_on_connection_exception(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        $result = $this->tester->connect(['endpoint' => 'https://unreachable.local']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Connection failed:', $result->message);
    }
}
