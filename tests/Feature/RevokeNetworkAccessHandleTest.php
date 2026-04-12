<?php

namespace Tests\Feature;

use App\Jobs\RevokeNetworkAccess;
use App\Models\IpAddress;
use App\Models\User;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class RevokeNetworkAccessHandleTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_calls_deny(): void
    {
        Log::spy();

        $user = User::factory()->create();

        $mockIp = Mockery::mock(IpAddress::class)->makePartial();
        $mockIp->shouldReceive('deny')->once();
        $mockIp->address = '10.0.0.1';

        $job = new RevokeNetworkAccess($user, $mockIp);
        $job->handle();

        Log::shouldHaveReceived('info')->once();
    }

    public function test_failed_logs_error(): void
    {
        Log::spy();

        $user = User::factory()->create();
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $job = new RevokeNetworkAccess($user, $ip);
        $job->failed(new Exception('Test error'));

        Log::shouldHaveReceived('error')->once();
    }
}
