<?php

declare(strict_types=1);

namespace Tests\Unit\Observers;

use App\Jobs\SyncUserPolicyJob;
use App\Models\IpAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UserObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_sync_user_policy_job_when_internet_enabled_changes(): void
    {
        Queue::fake();

        $user = User::factory()->create(['internet_enabled' => false]);
        $ip = IpAddress::factory()->create();
        $this->createUserIp($user, $ip);

        $user->internet_enabled = true;
        $user->save();

        Queue::assertPushed(SyncUserPolicyJob::class, function (SyncUserPolicyJob $job) use ($user, $ip): bool {
            return $job->user->is($user) && $job->ip->is($ip);
        });
    }

    public function test_dispatches_sync_user_policy_job_when_internet_blocked_changes(): void
    {
        Queue::fake();

        $user = User::factory()->create(['internet_blocked' => false]);
        $ip = IpAddress::factory()->create();
        $this->createUserIp($user, $ip);

        $user->internet_blocked = true;
        $user->save();

        Queue::assertPushed(SyncUserPolicyJob::class, function (SyncUserPolicyJob $job) use ($user, $ip): bool {
            return $job->user->is($user) && $job->ip->is($ip);
        });
    }

    public function test_does_not_dispatch_when_unrelated_field_changes(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $ip = IpAddress::factory()->create();
        $this->createUserIp($user, $ip);

        $user->nickname = 'new-nickname';
        $user->save();

        Queue::assertNotPushed(SyncUserPolicyJob::class);
    }

    public function test_dispatches_one_job_per_associated_ip(): void
    {
        Queue::fake();

        $user = User::factory()->create(['internet_enabled' => false]);
        $ip1 = IpAddress::factory()->create();
        $ip2 = IpAddress::factory()->create();
        $this->createUserIp($user, $ip1);
        $this->createUserIp($user, $ip2);

        $user->internet_enabled = true;
        $user->save();

        Queue::assertPushed(SyncUserPolicyJob::class, 2);
    }

    private function createUserIp(User $user, IpAddress $ip): UserIpAddress
    {
        $userIp = new UserIpAddress;
        $userIp->user()->associate($user);
        $userIp->ip()->associate($ip);
        $userIp->last_seen_at = now();
        $userIp->save();

        return $userIp;
    }
}
