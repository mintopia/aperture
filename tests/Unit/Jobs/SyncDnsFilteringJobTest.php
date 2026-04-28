<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\SyncDnsFilteringJob;
use App\Services\Interfaces\DnsFilteringInterface;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class SyncDnsFilteringJobTest extends TestCase
{
    public function test_has_correct_retry_configuration(): void
    {
        $job = new SyncDnsFilteringJob('10.0.0.1', true);

        $this->assertSame(3, $job->tries);
        $this->assertSame(30, $job->timeout);
        $this->assertSame([2, 10, 30], $job->backoff());
    }

    public function test_failed_logs_error(): void
    {
        $job = new SyncDnsFilteringJob('10.0.0.1', true);

        Log::shouldReceive('error')
            ->once()
            ->with('SyncDnsFilteringJob failed', [
                'ip' => '10.0.0.1',
                'enabled' => true,
                'error' => 'DNS service unavailable',
            ]);

        $job->failed(new RuntimeException('DNS service unavailable'));
    }

    public function test_enables_dns_filtering_for_ip(): void
    {
        $this->mock(DnsFilteringInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('enableForIp')->with('10.0.0.1')->once();
            $mock->shouldNotReceive('disableForIp');
        });

        $job = new SyncDnsFilteringJob('10.0.0.1', true);
        $this->app->call([$job, 'handle']);
    }

    public function test_disables_dns_filtering_for_ip(): void
    {
        $this->mock(DnsFilteringInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('disableForIp')->with('10.0.0.1')->once();
            $mock->shouldNotReceive('enableForIp');
        });

        $job = new SyncDnsFilteringJob('10.0.0.1', false);
        $this->app->call([$job, 'handle']);
    }
}
