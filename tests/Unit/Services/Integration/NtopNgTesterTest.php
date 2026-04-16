<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Integration;

use App\Services\Integration\NtopNgTester;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NtopNgTesterTest extends TestCase
{
    private NtopNgTester $tester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tester = new NtopNgTester;
    }

    public function test_returns_success_on_200_response(): void
    {
        Http::fake(['*' => Http::response(['rc' => 0], 200)]);

        $result = $this->tester->connect([
            'endpoint' => 'https://ntopng.local',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('Connected successfully', $result->message);
        $this->assertSame('GET', $result->requestMethod);
        $this->assertStringContainsString('/lua/pro/rest/v2/get/system/data.lua', $result->requestUrl);
        $this->assertSame(200, $result->responseStatus);
    }

    public function test_returns_failure_on_401_response(): void
    {
        Http::fake(['*' => Http::response('Unauthorized', 401)]);

        $result = $this->tester->connect([
            'endpoint' => 'https://ntopng.local',
            'username' => 'bad-user',
            'password' => 'bad-pass',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Connection failed:', $result->message);
    }

    public function test_sends_basic_auth_credentials(): void
    {
        Http::fake(['*' => Http::response(['rc' => 0], 200)]);

        $this->tester->connect([
            'endpoint' => 'https://ntopng.local',
            'username' => 'admin',
            'password' => 'secret123',
        ]);

        Http::assertSent(function ($request) {
            $auth = $request->header('Authorization');

            return ! empty($auth)
                && $auth[0] === 'Basic '.base64_encode('admin:secret123');
        });
    }

    public function test_sends_empty_auth_when_credentials_missing(): void
    {
        Http::fake(['*' => Http::response(['rc' => 0], 200)]);

        $this->tester->connect(['endpoint' => 'https://ntopng.local']);

        Http::assertSent(function ($request) {
            $auth = $request->header('Authorization');

            return ! empty($auth)
                && $auth[0] === 'Basic '.base64_encode(':');
        });
    }

    public function test_trims_trailing_slash_from_endpoint(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->tester->connect(['endpoint' => 'https://ntopng.local/']);

        Http::assertSent(fn ($req) => str_contains(
            $req->url(),
            'https://ntopng.local/lua/pro/rest/v2/get/system/data.lua'
        ));
    }
}
