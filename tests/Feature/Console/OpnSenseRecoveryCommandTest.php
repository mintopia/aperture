<?php

namespace Tests\Feature\Console;

use App\Models\IpAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use App\Services\Firewalls\OpnSense;
use App\Services\Interfaces\FirewallBackendInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class OpnSenseRecoveryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_exits_early_when_uptime_over_hour(): void
    {
        $mock = Mockery::mock(OpnSense::class);
        $mock->shouldReceive('getUptime')->andReturn(7200);
        $this->app->instance(OpnSense::class, $mock);

        Cache::put('opnsense.uptime', 3600);

        $this->artisan('aperture:opnsense-recovery')
            ->assertSuccessful();
    }

    public function test_command_exits_when_uptime_higher_than_last(): void
    {
        $mock = Mockery::mock(OpnSense::class);
        $mock->shouldReceive('getUptime')->andReturn(600);
        $this->app->instance(OpnSense::class, $mock);

        Cache::put('opnsense.uptime', 300);

        $this->artisan('aperture:opnsense-recovery')
            ->assertSuccessful();
    }

    public function test_command_restores_ips_when_reboot_detected(): void
    {
        $mock = Mockery::mock(OpnSense::class);
        $mock->shouldReceive('getUptime')->andReturn(100);
        $mock->shouldReceive('updateIp')->andReturnSelf();
        $this->app->instance(OpnSense::class, $mock);
        $this->app->instance(FirewallBackendInterface::class, $mock);

        Cache::put('opnsense.uptime', 3500);

        // No IPs in DB, so no processIp calls - just the reboot detection path
        $this->artisan('aperture:opnsense-recovery')
            ->assertSuccessful();
    }

    public function test_command_skips_blocked_users(): void
    {
        $mock = Mockery::mock(OpnSense::class);
        $mock->shouldReceive('getUptime')->andReturn(100);
        $this->app->instance(OpnSense::class, $mock);

        Cache::put('opnsense.uptime', 3500);

        $user = User::factory()->create(['blocked' => true]);
        $ip = new IpAddress;
        $ip->address = '10.0.0.2';
        $ip->last_seen_at = now();
        $ip->save();

        $userIp = new UserIpAddress;
        $userIp->user()->associate($user);
        $userIp->ip()->associate($ip);
        $userIp->last_seen_at = now();
        $userIp->save();

        $this->artisan('aperture:opnsense-recovery')
            ->assertSuccessful();
    }

    public function test_command_processes_unblocked_user_ips(): void
    {
        $mock = Mockery::mock(OpnSense::class);
        $mock->shouldReceive('getUptime')->andReturn(100);
        $mock->shouldReceive('updateIp')->andReturnSelf();
        $mock->shouldReceive('removeIp')->andReturnSelf();
        $this->app->instance(OpnSense::class, $mock);
        $this->app->instance(FirewallBackendInterface::class, $mock);

        Cache::put('opnsense.uptime', 3500);

        $user = User::factory()->create(['blocked' => false]);
        $ip = new IpAddress;
        $ip->address = '10.0.0.10';
        $ip->last_seen_at = now();
        $ip->save();

        $userIp = new UserIpAddress;
        $userIp->user()->associate($user);
        $userIp->ip()->associate($ip);
        $userIp->last_seen_at = now();
        $userIp->save();

        $this->artisan('aperture:opnsense-recovery')
            ->assertSuccessful();

        $ip->refresh();
        $this->assertTrue((bool) $ip->allowed);
    }
}
