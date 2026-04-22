<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\SyncUserPolicyJob;
use App\Models\IpAddress;
use App\Models\User;
use App\Services\IpPolicyService;
use Mockery\MockInterface;
use Tests\TestCase;

class SyncUserPolicyJobTest extends TestCase
{
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
