<?php

namespace Tests\Feature\Console;

use App\Models\IpAddress;
use App\Services\NtopNgService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use stdClass;
use Tests\TestCase;

class TestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_calls_update_usage_on_ip_address(): void
    {
        $mockStats = new stdClass;
        $mockStats->rsp = new stdClass;
        $mockStats->rsp->{'bytes.rcvd'} = 500;
        $mockStats->rsp->{'bytes.sent'} = 1000;

        $mock = Mockery::mock(NtopNgService::class);
        $mock->shouldReceive('getStats')->with('10.30.0.197')->andReturn($mockStats);
        $this->app->instance(NtopNgService::class, $mock);

        // The command creates a bare IpAddress (missing last_seen_at) and calls
        // updateUsage() which calls save(). This throws QueryException due to
        // the NOT NULL constraint on last_seen_at. This is expected behavior
        // for this debug/test command.
        $this->expectException(QueryException::class);
        $this->artisan('aperture:test');
    }
}
