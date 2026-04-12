<?php

namespace Tests\Feature;

use App\Jobs\ReapplyAccessRules;
use App\Models\IpAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ReapplyAccessRulesJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_reapplies_access_for_allowed_ips(): void
    {
        Log::spy();

        // With no allowed IPs, job should just log the count
        $job = new ReapplyAccessRules;
        $job->handle();

        Log::shouldHaveReceived('info')->once();
    }

    public function test_handle_handles_exception_per_ip(): void
    {
        Log::spy();

        $user = User::factory()->create();

        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->allowed = true;
        $ip->last_seen_at = now();
        $ip->save();

        $userIp = new UserIpAddress;
        $userIp->user()->associate($user);
        $userIp->ip()->associate($ip);
        $userIp->last_seen_at = now();
        $userIp->save();

        // The allow() method will throw since there's no OpnSense
        // The job should catch the exception and log a warning
        config([
            'aperture.opnsense.zoneid' => 1,
            'aperture.opnsense.ratelimitUpUuid' => 'uuid',
            'aperture.opnsense.ratelimitDownUuid' => 'uuid',
            'aperture.opnsense.verify' => false,
            'aperture.opnsense.endpoint' => 'http://nonexistent.local',
            'aperture.opnsense.key' => 'key',
            'aperture.opnsense.secret' => 'secret',
        ]);

        $job = new ReapplyAccessRules;
        $job->handle();

        Log::shouldHaveReceived('warning')->once();
        Log::shouldHaveReceived('info')->once();
    }
}
