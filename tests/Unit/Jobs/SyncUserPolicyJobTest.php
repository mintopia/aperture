<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\SyncUserPolicyJob;
use App\Models\IpAddress;
use App\Models\User;
use App\Services\IpPolicyService;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Tests\TestCase;

class SyncUserPolicyJobTest extends TestCase
{
    public function test_has_correct_retry_configuration(): void
    {
        $user = User::factory()->make();
        $ip = IpAddress::factory()->make();
        $job = new SyncUserPolicyJob($user, $ip);

        $this->assertSame(3, $job->tries);
        $this->assertSame(30, $job->timeout);
        $this->assertSame([2, 10, 30], $job->backoff());
    }

    public function test_failed_logs_error(): void
    {
        $user = User::factory()->make(['id' => 42]);
        $ip = IpAddress::factory()->make(['address' => '10.0.0.50']);
        $job = new SyncUserPolicyJob($user, $ip);

        Log::shouldReceive('error')
            ->once()
            ->with('SyncUserPolicyJob failed', [
                'user_id' => 42,
                'ip' => '10.0.0.50',
                'error' => 'Policy service error',
            ]);

        $job->failed(new \RuntimeException('Policy service error'));
    }

    public function test_applies_user_policy_to_ip(): void
    {
        $user = User::factory()->make();
        $ip = IpAddress::factory()->make();

        $this->mock(IpPolicyService::class, function (MockInterface $mock) use ($user, $ip): void {
            $mock->shouldReceive('applyUserPolicy')->with($user, $ip)->once();
        });

        $job = new SyncUserPolicyJob($user, $ip);
        $this->app->call([$job, 'handle']);
    }
}
