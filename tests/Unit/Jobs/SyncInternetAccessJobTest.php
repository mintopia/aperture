<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\SyncInternetAccessJob;
use App\Models\IpAddress;
use App\Services\IpAddressActionService;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class SyncInternetAccessJobTest extends TestCase
{
    public function test_has_correct_retry_configuration(): void
    {
        $ip = IpAddress::factory()->make();
        $job = new SyncInternetAccessJob($ip, true);

        $this->assertSame(3, $job->tries);
        $this->assertSame(30, $job->timeout);
        $this->assertSame([2, 10, 30], $job->backoff());
    }

    public function test_failed_logs_error(): void
    {
        $ip = IpAddress::factory()->make(['address' => '10.0.0.50']);
        $job = new SyncInternetAccessJob($ip, true);

        Log::shouldReceive('error')
            ->once()
            ->with('SyncInternetAccessJob failed', [
                'ip' => '10.0.0.50',
                'enabled' => true,
                'error' => 'Connection timed out',
            ]);

        $job->failed(new RuntimeException('Connection timed out'));
    }

    public function test_enables_internet_access_for_ip(): void
    {
        $ip = IpAddress::factory()->make();

        $this->mock(IpAddressActionService::class, function (MockInterface $mock) use ($ip): void {
            $mock->shouldReceive('enableInternet')->with($ip)->once();
            $mock->shouldNotReceive('disableInternet');
        });

        $job = new SyncInternetAccessJob($ip, true);
        $this->app->call([$job, 'handle']);
    }

    public function test_disables_internet_access_for_ip(): void
    {
        $ip = IpAddress::factory()->make();

        $this->mock(IpAddressActionService::class, function (MockInterface $mock) use ($ip): void {
            $mock->shouldReceive('disableInternet')->with($ip)->once();
            $mock->shouldNotReceive('enableInternet');
        });

        $job = new SyncInternetAccessJob($ip, false);
        $this->app->call([$job, 'handle']);
    }
}
