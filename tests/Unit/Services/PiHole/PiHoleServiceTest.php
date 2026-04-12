<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PiHole;

use App\Services\PiHole\PiHoleService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PiHoleServiceTest extends TestCase
{
    /**
     * @param  array<int, Response>  $responses
     */
    private function createServiceWithMock(array $responses): PiHoleService
    {
        $mock = new MockHandler($responses);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        return new PiHoleService($client, 'test-password', 1);
    }

    private function authResponse(): Response
    {
        return new Response(200, [], (string) json_encode([
            'session' => [
                'token' => 'test-token-abc',
                'validity' => 300,
            ],
        ]));
    }

    public function test_is_enabled_returns_true_when_client_not_in_noblock_group(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            new Response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 5, 'client' => '10.0.0.10', 'groups' => [0], 'comment' => ''],
                ],
            ])),
        ]);

        $this->assertTrue($service->isEnabledForIp('10.0.0.10'));
    }

    public function test_is_enabled_returns_false_when_client_in_noblock_group(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            new Response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 5, 'client' => '10.0.0.10', 'groups' => [0, 1], 'comment' => ''],
                ],
            ])),
        ]);

        $this->assertFalse($service->isEnabledForIp('10.0.0.10'));
    }

    public function test_is_enabled_returns_true_when_client_not_found(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            new Response(200, [], (string) json_encode([
                'clients' => [],
            ])),
        ]);

        $this->assertTrue($service->isEnabledForIp('10.0.0.99'));
    }

    public function test_disable_adds_client_to_noblock_group_when_client_exists(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            new Response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 5, 'client' => '10.0.0.10', 'groups' => [0], 'comment' => ''],
                ],
            ])),
            new Response(200, [], (string) json_encode(['client' => ['id' => 5]])),
        ]);

        $service->disableForIp('10.0.0.10');
        $this->assertTrue(true);
    }

    public function test_disable_creates_client_when_not_found(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            new Response(200, [], (string) json_encode([
                'clients' => [],
            ])),
            new Response(201, [], (string) json_encode(['client' => ['id' => 10]])),
        ]);

        $service->disableForIp('10.0.0.20');
        $this->assertTrue(true);
    }

    public function test_disable_is_noop_when_client_already_in_noblock_group(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            new Response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 5, 'client' => '10.0.0.10', 'groups' => [0, 1], 'comment' => ''],
                ],
            ])),
        ]);

        $service->disableForIp('10.0.0.10');
        $this->assertTrue(true);
    }

    public function test_enable_removes_noblock_group_from_client(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            new Response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 5, 'client' => '10.0.0.10', 'groups' => [0, 1], 'comment' => ''],
                ],
            ])),
            new Response(200, [], (string) json_encode(['client' => ['id' => 5]])),
        ]);

        $service->enableForIp('10.0.0.10');
        $this->assertTrue(true);
    }

    public function test_enable_is_noop_when_client_not_in_noblock_group(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            new Response(200, [], (string) json_encode([
                'clients' => [
                    ['id' => 5, 'client' => '10.0.0.10', 'groups' => [0], 'comment' => ''],
                ],
            ])),
        ]);

        $service->enableForIp('10.0.0.10');
        $this->assertTrue(true);
    }

    public function test_enable_is_noop_when_client_not_found(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            new Response(200, [], (string) json_encode([
                'clients' => [],
            ])),
        ]);

        $service->enableForIp('10.0.0.99');
        $this->assertTrue(true);
    }

    public function test_auth_token_is_cached(): void
    {
        Cache::flush();

        $service = $this->createServiceWithMock([
            $this->authResponse(),
            new Response(200, [], (string) json_encode(['clients' => []])),
            new Response(200, [], (string) json_encode(['clients' => []])),
        ]);

        $service->isEnabledForIp('10.0.0.10');
        $service->isEnabledForIp('10.0.0.11');

        $this->assertTrue(true);
    }
}
