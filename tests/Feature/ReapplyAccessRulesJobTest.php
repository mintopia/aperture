<?php

namespace Tests\Feature;

use App\Jobs\ReapplyAccessRules;
use App\Models\IpAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class ReapplyAccessRulesJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_has_correct_retry_configuration(): void
    {
        $job = new ReapplyAccessRules;

        $this->assertSame(3, $job->tries);
        $this->assertSame(120, $job->timeout);
        $this->assertSame([10, 30, 60], $job->backoff());
    }

    public function test_failed_logs_error(): void
    {
        $job = new ReapplyAccessRules;

        Log::shouldReceive('error')
            ->once()
            ->with('ReapplyAccessRules failed', [
                'error' => 'Database connection lost',
            ]);

        $job->failed(new RuntimeException('Database connection lost'));
    }

    public function test_handle_reapplies_access_for_allowed_ips(): void
    {
        Log::spy();

        // With no allowed IPs, job should just log the count
        $job = new ReapplyAccessRules;
        $job->handle();

        Log::shouldHaveReceived('info')->once();
    }

    public function test_handle_reapplies_access_for_internet_enabled_ips(): void
    {
        Log::spy();

        $user = User::factory()->create();

        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->internet_enabled = true;
        $ip->last_seen_at = now();
        $ip->save();

        $userIp = new UserIpAddress;
        $userIp->user()->associate($user);
        $userIp->ip()->associate($ip);
        $userIp->last_seen_at = now();
        $userIp->save();

        $job = new ReapplyAccessRules;
        $job->handle();

        Log::shouldHaveReceived('info')->once();
    }
}
