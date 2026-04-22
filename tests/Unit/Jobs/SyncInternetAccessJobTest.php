<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\SyncInternetAccessJob;
use App\Models\IpAddress;
use App\Services\IpAddressActionService;
use Mockery\MockInterface;
use Tests\TestCase;

class SyncInternetAccessJobTest extends TestCase
{
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
