<?php

declare(strict_types=1);

namespace Tests\Unit\Services\VyOs;

use App\Services\VyOs\VyOsClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class VyOsClientTest extends TestCase
{
    private VyOsClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new VyOsClient(
            endpoint: 'https://vyos.local',
            apiKey: 'test-api-key',
        );
    }

    public function test_retrieve_sends_post_with_correct_format(): void
    {
        Http::fake([
            'vyos.local/retrieve' => Http::response([
                'success' => true,
                'data' => ['some' => 'config'],
                'error' => null,
            ]),
        ]);

        $result = $this->client->retrieve(['service', 'dhcp-server']);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://vyos.local/retrieve'
                && $request->method() === 'POST'
                && $request->data()['key'] === 'test-api-key'
                && json_decode($request->data()['data'], true) === [
                    'op' => 'showConfig',
                    'path' => ['service', 'dhcp-server'],
                ];
        });

        $this->assertSame(['some' => 'config'], $result);
    }

    public function test_show_sends_post_with_correct_format(): void
    {
        Http::fake([
            'vyos.local/show' => Http::response([
                'success' => true,
                'data' => ['version' => '1.4.0'],
                'error' => null,
            ]),
        ]);

        $result = $this->client->show(['version']);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://vyos.local/show'
                && $request->method() === 'POST'
                && $request->data()['key'] === 'test-api-key'
                && json_decode($request->data()['data'], true) === [
                    'op' => 'show',
                    'path' => ['version'],
                ];
        });

        $this->assertSame(['version' => '1.4.0'], $result);
    }

    public function test_retrieve_trims_trailing_slash_from_endpoint(): void
    {
        $client = new VyOsClient(
            endpoint: 'https://vyos.local/',
            apiKey: 'key',
        );

        Http::fake([
            'vyos.local/retrieve' => Http::response([
                'success' => true,
                'data' => [],
                'error' => null,
            ]),
        ]);

        $client->retrieve(['test']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://vyos.local/retrieve');
    }

    public function test_retrieve_throws_on_non_success_response(): void
    {
        Http::fake([
            'vyos.local/retrieve' => Http::response([
                'success' => false,
                'data' => null,
                'error' => 'Invalid path',
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid path');

        $this->client->retrieve(['invalid', 'path']);
    }

    public function test_show_throws_on_non_success_response(): void
    {
        Http::fake([
            'vyos.local/show' => Http::response([
                'success' => false,
                'data' => null,
                'error' => 'Command failed',
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Command failed');

        $this->client->show(['bad', 'command']);
    }

    public function test_retrieve_throws_on_http_error(): void
    {
        Http::fake([
            'vyos.local/retrieve' => Http::response('Unauthorized', 401),
        ]);

        $this->expectException(RuntimeException::class);

        $this->client->retrieve(['service']);
    }

    public function test_show_throws_on_http_error(): void
    {
        Http::fake([
            'vyos.local/show' => Http::response('Server Error', 500),
        ]);

        $this->expectException(RuntimeException::class);

        $this->client->show(['version']);
    }

    public function test_retrieve_returns_empty_array_when_data_is_null(): void
    {
        Http::fake([
            'vyos.local/retrieve' => Http::response([
                'success' => true,
                'data' => null,
                'error' => null,
            ]),
        ]);

        $result = $this->client->retrieve(['service', 'nonexistent']);

        $this->assertSame([], $result);
    }

    public function test_show_text_returns_string_data(): void
    {
        Http::fake([
            'vyos.local/show' => Http::response([
                'success' => true,
                'data' => "192.168.1.1 dev eth0 lladdr aa:bb:cc:dd:ee:ff REACHABLE\n10.0.0.1 dev eth1 lladdr 11:22:33:44:55:66 STALE\n",
                'error' => null,
            ]),
        ]);

        $result = $this->client->showText(['ip', 'neighbors']);

        $this->assertStringContainsString('192.168.1.1', $result);
        $this->assertStringContainsString('aa:bb:cc:dd:ee:ff', $result);
    }

    public function test_show_returns_empty_array_when_data_is_string(): void
    {
        Http::fake([
            'vyos.local/show' => Http::response([
                'success' => true,
                'data' => 'some text output',
                'error' => null,
            ]),
        ]);

        $result = $this->client->show(['version']);

        $this->assertSame(['some text output'], $result);
    }

    public function test_verify_ssl_is_passed_to_http_client(): void
    {
        $client = new VyOsClient(
            endpoint: 'https://vyos.local',
            apiKey: 'key',
            verifySsl: false,
        );

        Http::fake([
            'vyos.local/show' => Http::response([
                'success' => true,
                'data' => [],
                'error' => null,
            ]),
        ]);

        $client->show(['version']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://vyos.local/show');
    }
}
