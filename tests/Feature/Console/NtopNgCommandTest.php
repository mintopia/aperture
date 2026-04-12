<?php

namespace Tests\Feature\Console;

use App\Models\IpAddress;
use App\Services\NtopNgService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use stdClass;
use Tests\TestCase;

class NtopNgCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_updates_usage_for_all_ips(): void
    {
        $mockStats = new stdClass;
        $mockStats->rsp = new stdClass;
        $mockStats->rsp->{'bytes.rcvd'} = 1000;
        $mockStats->rsp->{'bytes.sent'} = 2000;

        $mock = Mockery::mock(NtopNgService::class);
        $mock->shouldReceive('getStats')->andReturn($mockStats);
        $this->app->instance(NtopNgService::class, $mock);

        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $this->artisan('aperture:ntopng')
            ->assertSuccessful();

        $ip->refresh();
        $this->assertEquals(1000, $ip->received);
    }

    public function test_command_handles_empty_database(): void
    {
        $this->artisan('aperture:ntopng')
            ->assertSuccessful();
    }
}
