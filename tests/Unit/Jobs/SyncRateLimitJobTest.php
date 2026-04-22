<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\SyncRateLimitJob;
use App\Models\IpAddress;
use App\Services\IpAddressActionService;
use Mockery\MockInterface;
use Tests\TestCase;

class SyncRateLimitJobTest extends TestCase
{
    public function test_enables_rate_limit_for_ip(): void
    {
        $ip = IpAddress::factory()->make();

        $this->mock(IpAddressActionService::class, function (MockInterface $mock) use ($ip): void {
            $mock->shouldReceive('enableRateLimit')->with($ip)->once();
            $mock->shouldNotReceive('disableRateLimit');
        });

        $job = new SyncRateLimitJob($ip, true);
        $this->app->call([$job, 'handle']);
    }

    public function test_disables_rate_limit_for_ip(): void
    {
        $ip = IpAddress::factory()->make();

        $this->mock(IpAddressActionService::class, function (MockInterface $mock) use ($ip): void {
            $mock->shouldReceive('disableRateLimit')->with($ip)->once();
            $mock->shouldNotReceive('enableRateLimit');
        });

        $job = new SyncRateLimitJob($ip, false);
        $this->app->call([$job, 'handle']);
    }
}
