<?php

namespace Tests\Feature\Console;

use App\Services\Interfaces\HostStatsProviderInterface;
use App\Services\ValueObjects\HostBytes;
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
        /** @var HostStatsProviderInterface&MockInterface $mock */
        $mock = Mockery::mock(HostStatsProviderInterface::class);
        $mock->shouldReceive('getHostBytes')
            ->with('10.30.0.197')
            ->andReturn(new HostBytes(received: 500, sent: 1000));
        $this->app->instance(HostStatsProviderInterface::class, $mock);

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

    public function test_command_logs_warning_when_host_stats_fails(): void
    {
        /** @var HostStatsProviderInterface&MockInterface $mock */
        $mock = Mockery::mock(HostStatsProviderInterface::class);
        $mock->shouldReceive('getHostBytes')
            ->with('10.30.0.197')
            ->andThrow(new RuntimeException('Connection refused'));
        $this->app->instance(HostStatsProviderInterface::class, $mock);

        Log::shouldReceive('warning')
            ->once()
            ->with('Failed to update usage for IP address', Mockery::on(function (array $context): bool {
                return $context['ip'] === '10.30.0.197'
                    && str_contains($context['error'], 'Connection refused');
            }));

        $this->artisan('aperture:test')->assertExitCode(0);
    }
}
