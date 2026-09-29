<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Integration;

use App\Services\Integration\BorealisTester;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BorealisTesterTest extends TestCase
{
    private BorealisTester $tester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tester = new BorealisTester;
    }

    public function test_returns_success_on_200_response(): void
    {
        Http::fake(['*' => Http::response([
            'device_code' => 'test-code',
            'user_code' => 'TEST',
            'verification_uri' => 'https://auth.example.com/verify',
            'expires_in' => 300,
            'interval' => 5,
        ], 200)]);

        $result = $this->tester->connect([
            'endpoint' => 'https://borealis.example.com',
            'client_id' => 'my-client',
            'client_secret' => 'my-secret',
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('Authenticated and received device code.', $result->message);
        $this->assertSame('POST', $result->requestMethod);
        $this->assertStringContainsString('/oauth2/device', $result->requestUrl);
        $this->assertSame(200, $result->responseStatus);
    }

    public function test_returns_failure_on_401_response(): void
    {
        Http::fake(['*' => Http::response('Unauthorized', 401)]);

        $result = $this->tester->connect([
            'endpoint' => 'https://borealis.example.com',
            'client_id' => 'bad-client',
            'client_secret' => 'bad-secret',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Connection failed:', $result->message);
    }

    public function test_sends_basic_auth_credentials(): void
    {
        Http::fake(['*' => Http::response(['device_code' => 'x'], 200)]);

        $this->tester->connect([
            'endpoint' => 'https://borealis.example.com',
            'client_id' => 'my-client-id',
            'client_secret' => 'my-client-secret',
        ]);

        Http::assertSent(function ($request): bool {
            $auth = $request->header('Authorization');

            return ! empty($auth)
                && $auth[0] === 'Basic '.base64_encode('my-client-id:my-client-secret');
        });
    }

    public function test_sends_scope_in_form_body(): void
    {
        Http::fake(['*' => Http::response(['device_code' => 'x'], 200)]);

        $this->tester->connect([
            'endpoint' => 'https://borealis.example.com',
            'scope' => 'discord',
        ]);

        Http::assertSent(function ($request): bool {
            parse_str($request->body(), $body);

            return isset($body['scope']) && $body['scope'] === 'discord';
        });
    }

    public function test_falls_back_to_test_scope_when_not_configured(): void
    {
        Http::fake(['*' => Http::response(['device_code' => 'x'], 200)]);

        $this->tester->connect(['endpoint' => 'https://borealis.example.com']);

        Http::assertSent(function ($request): bool {
            parse_str($request->body(), $body);

            return isset($body['scope']) && $body['scope'] === 'test';
        });
    }

    public function test_posts_as_form_data(): void
    {
        Http::fake(['*' => Http::response(['device_code' => 'x'], 200)]);

        $this->tester->connect(['endpoint' => 'https://borealis.example.com']);

        Http::assertSent(function ($request): bool {
            $ct = $request->header('Content-Type')[0] ?? '';

            return str_contains($ct, 'application/x-www-form-urlencoded');
        });
    }

    public function test_trims_trailing_slash_from_endpoint(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->tester->connect(['endpoint' => 'https://borealis.example.com/']);

        Http::assertSent(fn ($req): bool => str_contains(
            $req->url(),
            'https://borealis.example.com/oauth2/device'
        ));
    }
}
