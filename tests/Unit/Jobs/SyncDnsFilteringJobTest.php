<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\SyncDnsFilteringJob;
use App\Models\IpAddress;
use App\Services\Interfaces\DnsFilteringInterface;
use App\Support\Queues;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class SyncDnsFilteringJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_has_correct_retry_configuration(): void
    {
        $job = new SyncDnsFilteringJob('10.0.0.1');

        $this->assertSame(3, $job->tries);
        $this->assertSame(30, $job->timeout);
        $this->assertSame([2, 10, 30], $job->backoff());
        $this->assertSame(Queues::ACCESS, $job->queue);
    }

    public function test_serialises_per_ip_with_overlap_middleware(): void
    {
        $middleware = (new SyncDnsFilteringJob('10.0.0.1'))->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame(SyncDnsFilteringJob::class.':10.0.0.1', $middleware[0]->key);
        $this->assertNotNull($middleware[0]->expiresAfter);
    }

    public function test_failed_logs_error(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->with('SyncDnsFilteringJob failed', [
                'ip' => '10.0.0.1',
                'error' => 'Connection timed out',
            ]);

        (new SyncDnsFilteringJob('10.0.0.1'))->failed(new RuntimeException('Connection timed out'));
    }

    public function test_enables_when_ip_is_currently_enabled(): void
    {
        $ip = IpAddress::factory()->create(['dns_filtering_enabled' => true]);

        $this->mock(DnsFilteringInterface::class, function (MockInterface $mock) use ($ip): void {
            $mock->shouldReceive('enableForIp')->with($ip->address)->once();
            $mock->shouldNotReceive('disableForIp');
        });

        $this->app->call([new SyncDnsFilteringJob($ip->address), 'handle']);
    }

    public function test_delayed_enable_retry_applies_the_latest_disabled_state(): void
    {
        $ip = IpAddress::factory()->create(['dns_filtering_enabled' => true]);
        $staleEnableJob = new SyncDnsFilteringJob($ip->address);

        $ip->update(['dns_filtering_enabled' => false]);

        $this->mock(DnsFilteringInterface::class, function (MockInterface $mock) use ($ip): void {
            $mock->shouldReceive('disableForIp')->with($ip->address)->once();
            $mock->shouldNotReceive('enableForIp');
        });

        $this->app->call([$staleEnableJob, 'handle']);
    }

    public function test_skips_when_ip_no_longer_exists(): void
    {
        $this->mock(DnsFilteringInterface::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('enableForIp');
            $mock->shouldNotReceive('disableForIp');
        });

        $this->app->call([new SyncDnsFilteringJob('10.9.9.9'), 'handle']);
    }
}
