<?php

namespace Tests\Feature\Console;

use App\Models\IpAddress;
use App\Services\Interfaces\HostStatsProviderInterface;
use App\Services\ValueObjects\HostBytes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SyncBandwidthCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_updates_usage_for_all_ips(): void
    {
        $mock = Mockery::mock(HostStatsProviderInterface::class);
        $mock->shouldReceive('getHostBytes')
            ->andReturn(new HostBytes(received: 1000, sent: 2000));
        $this->app->instance(HostStatsProviderInterface::class, $mock);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);

        $this->artisan('aperture:sync-bandwidth')
            ->assertSuccessful();

        $ip->refresh();
        $this->assertEquals(1000, $ip->received);
    }

    public function test_command_handles_empty_database(): void
    {
        $this->artisan('aperture:sync-bandwidth')
            ->assertSuccessful();
    }
}
