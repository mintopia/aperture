<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PiHole;

use App\Services\PiHole\PiHoleApiService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PiHoleApiServiceTest extends TestCase
{
    public function test_get_groups_returns_error_when_endpoint_is_empty(): void
    {
        $result = PiHoleApiService::getGroups(['endpoint' => '', 'password' => 'secret']);

        $this->assertSame([], $result['groups']);
        $this->assertStringContainsString('endpoint is not configured', $result['error']);
    }

    public function test_get_groups_returns_error_when_password_is_empty(): void
    {
        $result = PiHoleApiService::getGroups(['endpoint' => 'http://pihole.local', 'password' => '']);

        $this->assertSame([], $result['groups']);
        $this->assertStringContainsString('password is not configured', $result['error']);
    }

    public function test_get_groups_returns_error_when_authentication_fails(): void
    {
        Http::fake([
            'pihole.local/api/auth' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        $result = PiHoleApiService::getGroups([
            'endpoint' => 'http://pihole.local',
            'password' => 'wrong',
            'verify_ssl' => false,
        ]);

        $this->assertSame([], $result['groups']);
        $this->assertStringContainsString('authentication failed', strtolower($result['error']));
    }

    public function test_get_groups_returns_error_when_session_id_is_missing(): void
    {
        Http::fake([
            'pihole.local/api/auth' => Http::response(['session' => ['sid' => '']], 200),
        ]);

        $result = PiHoleApiService::getGroups([
            'endpoint' => 'http://pihole.local',
            'password' => 'secret',
            'verify_ssl' => false,
        ]);

        $this->assertSame([], $result['groups']);
        $this->assertStringContainsString('session ID', $result['error']);
    }

    public function test_get_groups_returns_error_when_groups_fetch_fails(): void
    {
        Http::fake([
            'pihole.local/api/auth' => Http::response(['session' => ['sid' => 'test-sid']], 200),
            'pihole.local/api/groups' => Http::response('Server Error', 500),
        ]);

        $result = PiHoleApiService::getGroups([
            'endpoint' => 'http://pihole.local',
            'password' => 'secret',
            'verify_ssl' => false,
        ]);

        $this->assertSame([], $result['groups']);
        $this->assertStringContainsString('Failed to fetch Pi-hole groups', $result['error']);
    }

    public function test_get_groups_returns_mapped_groups_on_success(): void
    {
        Http::fake([
            'pihole.local/api/auth' => Http::response(['session' => ['sid' => 'test-sid']], 200),
            'pihole.local/api/groups' => Http::response([
                'groups' => [
                    ['id' => 0, 'name' => 'Default', 'enabled' => true],
                    ['id' => 1, 'name' => 'Noblock', 'enabled' => false],
                ],
            ], 200),
        ]);

        $result = PiHoleApiService::getGroups([
            'endpoint' => 'http://pihole.local',
            'password' => 'secret',
            'verify_ssl' => false,
        ]);

        $this->assertSame([
            ['id' => 0, 'name' => 'Default', 'enabled' => true],
            ['id' => 1, 'name' => 'Noblock', 'enabled' => false],
        ], $result['groups']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function test_get_groups_returns_error_on_exception(): void
    {
        Http::fake(fn () => throw new RuntimeException('Connection refused'));

        $result = PiHoleApiService::getGroups([
            'endpoint' => 'http://pihole.local',
            'password' => 'secret',
            'verify_ssl' => false,
        ]);

        $this->assertSame([], $result['groups']);
        $this->assertStringContainsString('Connection refused', $result['error']);
    }
}
