<?php

namespace Tests\Feature;

use App\Jobs\GrantNetworkAccess;
use App\Models\IpAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GrantNetworkAccessJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_can_be_dispatched(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->internet_enabled = false;
        $ip->save();

        GrantNetworkAccess::dispatch($user, $ip);

        Queue::assertPushed(GrantNetworkAccess::class);
    }

    public function test_job_has_correct_retry_config(): void
    {
        $user = User::factory()->create();
        $ip = new IpAddress;
        $ip->address = '10.0.0.2';
        $ip->last_seen_at = now();
        $ip->save();

        $job = new GrantNetworkAccess($user, $ip);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals([10, 30, 60], $job->backoff);
    }
}
