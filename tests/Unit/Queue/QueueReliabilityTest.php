<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use App\Jobs\GrantNetworkAccess;
use App\Jobs\ReapplyAccessRules;
use App\Jobs\ResetAperture;
use App\Jobs\RevokeNetworkAccess;
use App\Jobs\ScanNetworkDevices;
use App\Jobs\SwitchPortActionJob;
use App\Jobs\SyncDhcpData;
use App\Jobs\SyncDnsFilteringJob;
use App\Jobs\SyncInternetAccessJob;
use App\Jobs\SyncRateLimitJob;
use App\Jobs\SyncSwitchPortsJob;
use App\Jobs\SyncUserPolicyJob;
use App\Models\IpAddress;
use App\Models\SwitchConfig;
use App\Models\User;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class QueueReliabilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const int SAFETY_MARGIN = 30;

    /**
     * @return array<string, object>
     */
    private function jobs(): array
    {
        $ip = IpAddress::factory()->make();
        $user = User::factory()->make();
        $switch = SwitchConfig::factory()->make(['id' => 1]);

        return [
            'access' => [
                new GrantNetworkAccess($user, $ip),
                new RevokeNetworkAccess($user, $ip),
                new SyncInternetAccessJob($ip),
                new SyncRateLimitJob($ip),
                new SyncDnsFilteringJob($ip->address),
                new SyncUserPolicyJob($user, $ip),
            ],
            'switch' => [
                new SyncSwitchPortsJob($switch),
                new SwitchPortActionJob($switch, '1', 'enable'),
            ],
            'sync' => [
                new SyncDhcpData,
                new ScanNetworkDevices,
                new ReapplyAccessRules,
                new ResetAperture,
            ],
        ];
    }

    public function test_retry_after_exceeds_every_job_and_supervisor_timeout_with_margin(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');
        $timeouts = [];

        foreach ($this->jobs() as $group) {
            foreach ($group as $job) {
                $timeouts[$job::class] = $job->timeout ?? 60;
            }
        }

        foreach (config('horizon.defaults') as $name => $supervisor) {
            $timeouts['horizon:'.$name] = $supervisor['timeout'];
        }

        foreach ($timeouts as $name => $timeout) {
            $this->assertGreaterThanOrEqual($timeout + self::SAFETY_MARGIN, $retryAfter, 'retry_after too short for '.$name);
        }
    }

    public function test_horizon_supervisor_timeout_covers_the_jobs_on_its_queue(): void
    {
        $supervisors = [
            'access' => config('horizon.defaults.supervisor-access.timeout'),
            'switch' => config('horizon.defaults.supervisor-switch.timeout'),
            'sync' => config('horizon.defaults.supervisor-sync.timeout'),
        ];

        foreach ($this->jobs() as $group => $jobs) {
            foreach ($jobs as $job) {
                $this->assertGreaterThanOrEqual($job->timeout ?? 60, $supervisors[$group], $job::class);
            }
        }
    }

    public function test_jobs_declare_their_queue_and_horizon_serves_it(): void
    {
        $expected = ['access' => Queues::ACCESS, 'switch' => Queues::SWITCH, 'sync' => Queues::SYNC];
        $served = collect(config('horizon.defaults'))->pluck('queue')->flatten()->all();

        foreach ($this->jobs() as $group => $jobs) {
            foreach ($jobs as $job) {
                $this->assertSame($expected[$group], $job->queue, $job::class);
            }

            $this->assertContains($expected[$group], $served);
        }
    }

    public function test_long_running_scheduled_jobs_are_unique_with_lock_expiry(): void
    {
        foreach ([new SyncDhcpData, new ScanNetworkDevices, new ReapplyAccessRules] as $job) {
            $this->assertInstanceOf(ShouldBeUnique::class, $job);
            $this->assertGreaterThan($job->timeout, $job->uniqueFor, $job::class);
        }
    }
}
