<?php

namespace Tests\Unit\Jobs;

use App\Jobs\IpAddressAction;
use App\Models\IpAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class IpAddressActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_calls_specified_method(): void
    {
        $mockIp = Mockery::mock(IpAddress::class)->makePartial();
        $mockIp->shouldReceive('enableInternet')->once();

        $job = new IpAddressAction($mockIp, 'enableInternet');
        $job->handle();
    }

    public function test_handle_calls_disable_internet(): void
    {
        $mockIp = Mockery::mock(IpAddress::class)->makePartial();
        $mockIp->shouldReceive('disableInternet')->once();

        $job = new IpAddressAction($mockIp, 'disableInternet');
        $job->handle();
    }

    public function test_handle_calls_enable_rate_limit(): void
    {
        $mockIp = Mockery::mock(IpAddress::class)->makePartial();
        $mockIp->shouldReceive('enableRateLimit')->once();

        $job = new IpAddressAction($mockIp, 'enableRateLimit');
        $job->handle();
    }

    public function test_handle_calls_disable_rate_limit(): void
    {
        $mockIp = Mockery::mock(IpAddress::class)->makePartial();
        $mockIp->shouldReceive('disableRateLimit')->once();

        $job = new IpAddressAction($mockIp, 'disableRateLimit');
        $job->handle();
    }

    public function test_handle_calls_shut_port(): void
    {
        $mockIp = Mockery::mock(IpAddress::class)->makePartial();
        $mockIp->shouldReceive('shutPort')->once();

        $job = new IpAddressAction($mockIp, 'shutPort');
        $job->handle();
    }

    public function test_handle_calls_unshut_port(): void
    {
        $mockIp = Mockery::mock(IpAddress::class)->makePartial();
        $mockIp->shouldReceive('unshutPort')->once();

        $job = new IpAddressAction($mockIp, 'unshutPort');
        $job->handle();
    }
}
