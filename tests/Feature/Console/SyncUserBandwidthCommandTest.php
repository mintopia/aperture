<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\IpAddress;
use App\Models\User;
use App\Services\Interfaces\TrafficMonitorInterface;
use App\Services\ValueObjects\UserBandwidth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SyncUserBandwidthCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_updates_weekly_bandwidth_for_users_with_ips(): void
    {
        $user = User::factory()->create();
        $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);
        $user->addIp($ip->address);

        /** @var TrafficMonitorInterface&MockInterface $mock */
        $mock = Mockery::mock(TrafficMonitorInterface::class);
        $mock->shouldReceive('getUserBandwidth')
            ->once()
            ->with(Mockery::on(fn ($ips) => in_array('10.0.0.1', $ips, true)), '7d')
            ->andReturn(new UserBandwidth(
                received: 5000,
                sent: 3000,
                timestamps: [],
                download: [],
                upload: [],
            ));
        $this->app->instance(TrafficMonitorInterface::class, $mock);

        $this->artisan('aperture:sync-user-bandwidth')
            ->assertSuccessful();

        $user->refresh();
        $this->assertEquals(8000, $user->weekly_bandwidth);
    }

    public function test_command_skips_users_without_ips(): void
    {
        User::factory()->create();

        /** @var TrafficMonitorInterface&MockInterface $mock */
        $mock = Mockery::mock(TrafficMonitorInterface::class);
        $mock->shouldNotReceive('getUserBandwidth');
        $this->app->instance(TrafficMonitorInterface::class, $mock);

        $this->artisan('aperture:sync-user-bandwidth')
            ->assertSuccessful();
    }

    public function test_command_handles_empty_database(): void
    {
        $this->artisan('aperture:sync-user-bandwidth')
            ->assertSuccessful();
    }

    public function test_command_handles_traffic_monitor_exception_gracefully(): void
    {
        $user = User::factory()->create(['weekly_bandwidth' => 100]);
        $ip = IpAddress::factory()->create(['address' => '10.0.0.2']);
        $user->addIp($ip->address);

        /** @var TrafficMonitorInterface&MockInterface $mock */
        $mock = Mockery::mock(TrafficMonitorInterface::class);
        $mock->shouldReceive('getUserBandwidth')
            ->andThrow(new \RuntimeException('Connection refused'));
        $this->app->instance(TrafficMonitorInterface::class, $mock);

        $this->artisan('aperture:sync-user-bandwidth')
            ->assertSuccessful();

        $user->refresh();
        $this->assertEquals(100, $user->weekly_bandwidth);
    }

    public function test_command_aggregates_multiple_user_ips(): void
    {
        $user = User::factory()->create();
        $ip1 = IpAddress::factory()->create(['address' => '10.0.0.10']);
        $ip2 = IpAddress::factory()->create(['address' => '10.0.0.11']);
        $user->addIp($ip1->address);
        $user->addIp($ip2->address);

        /** @var TrafficMonitorInterface&MockInterface $mock */
        $mock = Mockery::mock(TrafficMonitorInterface::class);
        $mock->shouldReceive('getUserBandwidth')
            ->once()
            ->with(Mockery::on(fn ($ips) => count($ips) === 2), '7d')
            ->andReturn(new UserBandwidth(
                received: 10000,
                sent: 5000,
                timestamps: [],
                download: [],
                upload: [],
            ));
        $this->app->instance(TrafficMonitorInterface::class, $mock);

        $this->artisan('aperture:sync-user-bandwidth')
            ->assertSuccessful();

        $user->refresh();
        $this->assertEquals(15000, $user->weekly_bandwidth);
    }
}
