<?php

declare(strict_types=1);

namespace Tests\Unit\Observers;

use App\Jobs\SyncDnsFilteringJob;
use App\Jobs\SyncInternetAccessJob;
use App\Jobs\SyncRateLimitJob;
use App\Models\IpAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IpAddressObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_sync_internet_access_job_when_internet_enabled_changes(): void
    {
        Queue::fake();

        $ip = IpAddress::factory()->create(['internet_enabled' => false]);

        $ip->internet_enabled = true;
        $ip->save();

        Queue::assertPushed(SyncInternetAccessJob::class, function (SyncInternetAccessJob $job) use ($ip): bool {
            return $job->ip->is($ip) && $job->enabled;
        });
    }

    public function test_dispatches_sync_rate_limit_job_when_rate_limit_enabled_changes(): void
    {
        Queue::fake();

        $ip = IpAddress::factory()->create(['rate_limit_enabled' => false]);

        $ip->rate_limit_enabled = true;
        $ip->save();

        Queue::assertPushed(SyncRateLimitJob::class, function (SyncRateLimitJob $job) use ($ip): bool {
            return $job->ip->is($ip) && $job->enabled;
        });
    }

    public function test_dispatches_sync_dns_filtering_job_when_dns_filtering_enabled_changes(): void
    {
        Queue::fake();

        $ip = IpAddress::factory()->create(['dns_filtering_enabled' => false]);

        $ip->dns_filtering_enabled = true;
        $ip->save();

        Queue::assertPushed(SyncDnsFilteringJob::class, function (SyncDnsFilteringJob $job) use ($ip): bool {
            return $job->ipAddress === $ip->address && $job->enabled;
        });
    }

    public function test_dispatches_multiple_jobs_when_multiple_fields_change(): void
    {
        Queue::fake();

        $ip = IpAddress::factory()->create([
            'internet_enabled' => false,
            'rate_limit_enabled' => false,
            'dns_filtering_enabled' => false,
        ]);

        $ip->internet_enabled = true;
        $ip->rate_limit_enabled = true;
        $ip->dns_filtering_enabled = true;
        $ip->save();

        Queue::assertPushed(SyncInternetAccessJob::class, 1);
        Queue::assertPushed(SyncRateLimitJob::class, 1);
        Queue::assertPushed(SyncDnsFilteringJob::class, 1);
    }

    public function test_does_not_dispatch_when_non_policy_fields_change(): void
    {
        Queue::fake();

        $ip = IpAddress::factory()->create();

        $ip->comment = 'updated comment';
        $ip->save();

        Queue::assertNotPushed(SyncInternetAccessJob::class);
        Queue::assertNotPushed(SyncRateLimitJob::class);
        Queue::assertNotPushed(SyncDnsFilteringJob::class);
    }
}
