<?php

namespace Tests\Feature;

use App\Events\InternetAccessChanged;
use App\Jobs\GrantNetworkAccess;
use App\Models\IpAddress;
use App\Models\User;
use Exception;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class GrantNetworkAccessHandleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_handle_sets_internet_enabled_and_dispatches_event(): void
    {
        Event::fake();
        Log::spy();

        $user = User::factory()->create();

        $mockIp = Mockery::mock(IpAddress::class)->makePartial();
        $mockIp->shouldReceive('save')->once();
        $mockIp->address = '10.0.0.1';

        $job = new GrantNetworkAccess($user, $mockIp);
        $job->handle();

        $this->assertTrue($mockIp->internet_enabled);
        Event::assertDispatched(InternetAccessChanged::class);
    }

    public function test_failed_logs_error(): void
    {
        Log::spy();

        $user = User::factory()->create();
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $job = new GrantNetworkAccess($user, $ip);
        $job->failed(new Exception('Test error'));

        Log::shouldHaveReceived('error')->once();
    }
}
