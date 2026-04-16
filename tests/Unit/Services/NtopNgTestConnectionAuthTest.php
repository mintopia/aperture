<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Integration\NtopNgTester;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NtopNgTestConnectionAuthTest extends TestCase
{
    public function test_test_connection_sends_basic_auth_credentials(): void
    {
        Http::fake([
            '*' => Http::response(['rc' => 0], 200),
        ]);

        $config = [
            'endpoint' => 'https://ntopng.example.com',
            'username' => 'admin',
            'password' => 'secret123',
        ];

        $result = (new NtopNgTester)->connect($config);

        $this->assertTrue($result->success);

        Http::assertSent(function ($request) {
            $authHeader = $request->header('Authorization');

            return ! empty($authHeader)
                && str_starts_with($authHeader[0], 'Basic ')
                && $authHeader[0] === 'Basic '.base64_encode('admin:secret123');
        });
    }

    public function test_test_connection_sends_empty_auth_when_credentials_missing(): void
    {
        Http::fake([
            '*' => Http::response(['rc' => 0], 200),
        ]);

        $config = [
            'endpoint' => 'https://ntopng.example.com',
        ];

        $result = (new NtopNgTester)->connect($config);

        $this->assertTrue($result->success);

        Http::assertSent(function ($request) {
            $authHeader = $request->header('Authorization');

            return ! empty($authHeader)
                && str_starts_with($authHeader[0], 'Basic ')
                && $authHeader[0] === 'Basic '.base64_encode(':');
        });
    }
}
