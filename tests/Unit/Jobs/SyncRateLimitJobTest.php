<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\SyncRateLimitJob;
use App\Models\IpAddress;
use App\Services\IpAddressActionService;
use App\Support\Queues;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class SyncRateLimitJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_has_correct_retry_configuration(): void
    {
        $job = new SyncRateLimitJob(IpAddress::factory()->make());

        $this->assertSame(3, $job->tries);
        $this->assertSame(30, $job->timeout);
        $this->assertSame([2, 10, 30], $job->backoff());
        $this->assertSame(Queues::ACCESS, $job->queue);
    }

    public function test_serialises_per_ip_with_overlap_middleware(): void
    {
        $job = new SyncRateLimitJob(IpAddress::factory()->make(['address' => '10.0.0.50']));

        $middleware = $job->middleware();
        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame(SyncRateLimitJob::class.':10.0.0.50', $middleware[0]->key);
        $this->assertNotNull($middleware[0]->expiresAfter);
    }

    public function test_failed_logs_error(): void
    {
        $job = new SyncRateLimitJob(IpAddress::factory()->make(['address' => '10.0.0.50']));

        Log::shouldReceive('error')
            ->once()
            ->with('SyncRateLimitJob failed', [
                'ip' => '10.0.0.50',
                'error' => 'Connection timed out',
            ]);

        $job->failed(new RuntimeException('Connection timed out'));
    }

    public function test_enables_when_ip_is_currently_enabled(): void
    {
        $ip = IpAddress::factory()->create(['rate_limit_enabled' => true]);

        $this->mock(IpAddressActionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('enableRateLimit')->once();
            $mock->shouldNotReceive('disableRateLimit');
        });

        $this->app->call([new SyncRateLimitJob($ip), 'handle']);
    }

    public function test_disables_when_ip_is_currently_disabled(): void
    {
        $ip = IpAddress::factory()->create(['rate_limit_enabled' => false]);

        $this->mock(IpAddressActionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('disableRateLimit')->once();
            $mock->shouldNotReceive('enableRateLimit');
        });

        $this->app->call([new SyncRateLimitJob($ip), 'handle']);
    }

    public function test_delayed_enable_retry_applies_the_latest_disabled_state(): void
    {
        $ip = IpAddress::factory()->create(['rate_limit_enabled' => true]);
        $staleEnableJob = new SyncRateLimitJob($ip);

        $ip->update(['rate_limit_enabled' => false]);

        $this->mock(IpAddressActionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('disableRateLimit')->once();
            $mock->shouldNotReceive('enableRateLimit');
        });

        $this->app->call([$staleEnableJob, 'handle']);
    }

    public function test_skips_when_ip_no_longer_exists(): void
    {
        $ip = IpAddress::factory()->create();
        $job = new SyncRateLimitJob($ip);
        $ip->delete();

        $this->mock(IpAddressActionService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('enableRateLimit');
            $mock->shouldNotReceive('disableRateLimit');
        });

        $this->app->call([$job, 'handle']);
    }
}
