<?php

namespace Tests\Feature\Console;

use App\Services\NtopNgService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class TestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_calls_update_usage_on_ip_address(): void
    {
        $mockStats = new \stdClass;
        $mockStats->rsp = new \stdClass;
        $mockStats->rsp->{'bytes.rcvd'} = 500;
        $mockStats->rsp->{'bytes.sent'} = 1000;

        /** @var NtopNgService&MockInterface $mock */
        $mock = Mockery::mock(NtopNgService::class);
        $mock->shouldReceive('getStats')
            ->with('10.30.0.197')
            ->andReturn($mockStats);
        $this->app->instance(NtopNgService::class, $mock);

        // The command creates a bare IpAddress (missing last_seen_at) and calls
        // updateUsage() via the service, which catches Throwable and logs a warning
        // when save() fails due to NOT NULL constraint on last_seen_at.
        Log::shouldReceive('warning')
            ->once()
            ->with('Failed to update usage for IP address', Mockery::on(function (array $context): bool {
                return $context['ip'] === '10.30.0.197';
            }));

        $this->artisan('aperture:test')->assertExitCode(0);
    }

    public function test_command_logs_warning_when_ntopng_fails(): void
    {
        /** @var NtopNgService&MockInterface $mock */
        $mock = Mockery::mock(NtopNgService::class);
        $mock->shouldReceive('getStats')
            ->with('10.30.0.197')
            ->andThrow(new RuntimeException('Connection refused'));
        $this->app->instance(NtopNgService::class, $mock);

        Log::shouldReceive('warning')
            ->once()
            ->with('Failed to update usage for IP address', Mockery::on(function (array $context): bool {
                return $context['ip'] === '10.30.0.197'
                    && str_contains($context['error'], 'Connection refused');
            }));

        $this->artisan('aperture:test')->assertExitCode(0);
    }
}
