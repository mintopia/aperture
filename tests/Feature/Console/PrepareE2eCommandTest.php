<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

class PrepareE2eCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepare_e2e_creates_admin_user_and_fixtures(): void
    {
        $this->artisan('aperture:e2e:prepare', [
            '--email' => 'e2e@test.local',
            '--password' => 'testpass123',
            '--nickname' => 'e2e-admin',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'e2e@test.local')->first();
        $this->assertNotNull($user);
        $this->assertSame('e2e-admin', $user->nickname);

        $switch = SwitchConfig::query()->where('hostname', 'playwright-switch.local')->first();
        $this->assertNotNull($switch);
        $this->assertSame('Playwright Switch', $switch->name);

        $port1 = SwitchPort::query()
            ->where('switch_config_id', $switch->id)
            ->where('port_name', 'Gi1/0/1')
            ->first();
        $this->assertNotNull($port1);

        $port2 = SwitchPort::query()
            ->where('switch_config_id', $switch->id)
            ->where('port_name', 'Gi1/0/2')
            ->first();
        $this->assertNotNull($port2);
    }

    public function test_prepare_e2e_assigns_admin_and_user_roles(): void
    {
        $this->artisan('aperture:e2e:prepare', [
            '--email' => 'e2e@test.local',
            '--password' => 'testpass123',
            '--nickname' => 'e2e-admin',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'e2e@test.local')->first();
        $this->assertNotNull($user);

        $roleCodes = $user->roles()->pluck('code')->all();
        $this->assertContains('admin', $roleCodes);
        $this->assertContains('user', $roleCodes);
    }

    public function test_prepare_e2e_uses_default_options(): void
    {
        $this->artisan('aperture:e2e:prepare')
            ->assertSuccessful();

        $user = User::query()->where('email', 'playwright-admin@example.test')->first();
        $this->assertNotNull($user);
        $this->assertSame('playwright-admin', $user->nickname);
    }

    public function test_prepare_e2e_clears_rate_limiter_for_email(): void
    {
        $email = 'e2e-rate@test.local';

        // Simulate a locked out state first
        RateLimiter::hit('login-attempt:'.strtolower($email).'|127.0.0.1', 300);
        $this->assertGreaterThan(0, RateLimiter::attempts('login-attempt:'.strtolower($email).'|127.0.0.1'));

        $this->artisan('aperture:e2e:prepare', ['--email' => $email])
            ->assertSuccessful();

        // Rate limiter should have been cleared
        $this->assertEquals(0, RateLimiter::attempts('login-attempt:'.strtolower($email).'|127.0.0.1'));
    }

    public function test_prepare_e2e_is_idempotent_for_existing_user(): void
    {
        // Create the user first
        $this->artisan('aperture:e2e:prepare', [
            '--email' => 'e2e@test.local',
            '--nickname' => 'original-nick',
        ])->assertSuccessful();

        // Run again — should update without error and only one user exists
        $this->artisan('aperture:e2e:prepare', [
            '--email' => 'e2e@test.local',
            '--nickname' => 'updated-nick',
        ])->assertSuccessful();

        $count = User::query()->where('email', 'e2e@test.local')->count();
        $this->assertEquals(1, $count);
    }

    public function test_prepare_e2e_skips_redis_verification_when_not_using_redis(): void
    {
        // The test environment uses 'array' for cache/session, not redis.
        // So --verify-redis should warn but still return SUCCESS.
        config(['session.driver' => 'array', 'cache.default' => 'array']);

        $this->artisan('aperture:e2e:prepare', ['--verify-redis' => true])
            ->expectsOutputToContain('Redis verification skipped')
            ->assertSuccessful();
    }

    public function test_prepare_e2e_returns_failure_when_redis_connection_fails(): void
    {
        // Covers PrepareE2eCommand line 31: return self::FAILURE when verifyRedisConnections() returns false
        // Covers lines 139-154: Redis::connection() throws, error is logged, false is returned

        config(['session.driver' => 'redis', 'cache.default' => 'array']);

        // Mock Redis to throw on connection attempt
        Redis::shouldReceive('connection')
            ->with('default')
            ->andThrow(new RuntimeException('Redis connection refused'));

        $this->artisan('aperture:e2e:prepare', ['--verify-redis' => true])
            ->expectsOutputToContain('Redis connection [default] failed')
            ->assertFailed();
    }

    public function test_prepare_e2e_adds_cache_connection_when_cache_is_redis(): void
    {
        // Covers line 140-142: when cache.default === 'redis', 'cache' connection is added to the list
        // And then lines 158-160: when all succeed, returns SUCCESS

        config(['session.driver' => 'redis', 'cache.default' => 'redis']);

        Redis::shouldReceive('connection')
            ->with('default')
            ->andReturnSelf();

        Redis::shouldReceive('connection')
            ->with('cache')
            ->andReturnSelf();

        Redis::shouldReceive('command')
            ->with('PING')
            ->andReturn('PONG');

        $this->artisan('aperture:e2e:prepare', ['--verify-redis' => true])
            ->expectsOutputToContain('Redis verification passed')
            ->assertSuccessful();
    }
}
