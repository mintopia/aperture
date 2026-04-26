<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\SyncRateLimitJob;
use App\Models\IpAddress;
use App\Services\IpAddressActionService;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Tests\TestCase;

class SyncRateLimitJobTest extends TestCase
{
    public function test_has_correct_retry_configuration(): void
    {
        $ip = IpAddress::factory()->make();
        $job = new SyncRateLimitJob($ip, true);

        $this->assertSame(3, $job->tries);
        $this->assertSame(30, $job->timeout);
        $this->assertSame([2, 10, 30], $job->backoff());
    }

    public function test_failed_logs_error(): void
    {
        $ip = IpAddress::factory()->make(['address' => '10.0.0.50']);
        $job = new SyncRateLimitJob($ip, true);

        Log::shouldReceive('error')
            ->once()
            ->with('SyncRateLimitJob failed', [
                'ip' => '10.0.0.50',
                'enabled' => true,
                'error' => 'Connection timed out',
            ]);

        $job->failed(new \RuntimeException('Connection timed out'));
    }

    public function test_enables_rate_limit_for_ip(): void
    {
        $ip = IpAddress::factory()->make();

        $this->mock(IpAddressActionService::class, function (MockInterface $mock) use ($ip): void {
            $mock->shouldReceive('enableRateLimit')->with($ip)->once();
            $mock->shouldNotReceive('disableRateLimit');
        });

        $job = new SyncRateLimitJob($ip, true);
        $this->app->call([$job, 'handle']);
    }

    public function test_disables_rate_limit_for_ip(): void
    {
        $ip = IpAddress::factory()->make();

        $this->mock(IpAddressActionService::class, function (MockInterface $mock) use ($ip): void {
            $mock->shouldReceive('disableRateLimit')->with($ip)->once();
            $mock->shouldNotReceive('enableRateLimit');
        });

        $job = new SyncRateLimitJob($ip, false);
        $this->app->call([$job, 'handle']);
    }
}
