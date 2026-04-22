<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\SyncDnsFilteringJob;
use App\Services\Interfaces\DnsFilteringInterface;
use Mockery\MockInterface;
use Tests\TestCase;

class SyncDnsFilteringJobTest extends TestCase
{
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
