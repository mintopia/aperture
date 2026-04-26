<?php

namespace Tests\Feature;

use App\Jobs\RevokeNetworkAccess;
use App\Models\IpAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RevokeNetworkAccessJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_job_has_correct_retry_config(): void
    {
        $user = User::factory()->create();
        $ip = new IpAddress;
        $ip->address = '10.0.0.3';
        $ip->last_seen_at = now();
        $ip->save();

        $job = new RevokeNetworkAccess($user, $ip);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals([10, 30, 60], $job->backoff);
    }
}
