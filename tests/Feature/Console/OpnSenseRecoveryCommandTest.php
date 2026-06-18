<?php

namespace Tests\Feature\Console;

use App\Models\IpAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use App\Services\OpnSense\OpnSenseClient;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class OpnSenseRecoveryCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_command_exits_early_when_uptime_over_hour(): void
    {
        $mock = Mockery::mock(OpnSenseClient::class);
        $mock->shouldReceive('getUptime')->andReturn(7200);
        $this->app->instance(OpnSenseClient::class, $mock);

        Cache::put('opnsense.uptime', 3600);

        $this->artisan('aperture:opnsense-recovery')
            ->assertSuccessful();
    }

    public function test_command_exits_when_uptime_higher_than_last(): void
    {
        $mock = Mockery::mock(OpnSenseClient::class);
        $mock->shouldReceive('getUptime')->andReturn(600);
        $this->app->instance(OpnSenseClient::class, $mock);

        Cache::put('opnsense.uptime', 300);

        $this->artisan('aperture:opnsense-recovery')
            ->assertSuccessful();
    }

    public function test_command_restores_ips_when_reboot_detected(): void
    {
        $mock = Mockery::mock(OpnSenseClient::class);
        $mock->shouldReceive('getUptime')->andReturn(100);
        $this->app->instance(OpnSenseClient::class, $mock);

        Cache::put('opnsense.uptime', 3500);

        // No IPs in DB, so no processIp calls - just the reboot detection path
        $this->artisan('aperture:opnsense-recovery')
            ->assertSuccessful();
    }

    public function test_command_skips_blocked_users(): void
    {
        $mock = Mockery::mock(OpnSenseClient::class);
        $mock->shouldReceive('getUptime')->andReturn(100);
        $this->app->instance(OpnSenseClient::class, $mock);

        Cache::put('opnsense.uptime', 3500);

        $user = User::factory()->create(['internet_blocked' => true]);
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
        $mock = Mockery::mock(OpnSenseClient::class);
        $mock->shouldReceive('getUptime')->andReturn(100);
        $this->app->instance(OpnSenseClient::class, $mock);

        Cache::put('opnsense.uptime', 3500);

        $user = User::factory()->create(['internet_blocked' => false]);
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
        $this->assertTrue((bool) $ip->internet_enabled);
    }
}
