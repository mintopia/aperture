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
        $mockIp->shouldReceive('allow')->once();

        $job = new IpAddressAction($mockIp, 'allow');
        $job->handle();
    }

    public function test_handle_calls_deny(): void
    {
        $mockIp = Mockery::mock(IpAddress::class)->makePartial();
        $mockIp->shouldReceive('deny')->once();

        $job = new IpAddressAction($mockIp, 'deny');
        $job->handle();
    }

    public function test_handle_calls_limit(): void
    {
        $mockIp = Mockery::mock(IpAddress::class)->makePartial();
        $mockIp->shouldReceive('limit')->once();

        $job = new IpAddressAction($mockIp, 'limit');
        $job->handle();
    }

    public function test_handle_calls_unlimit(): void
    {
        $mockIp = Mockery::mock(IpAddress::class)->makePartial();
        $mockIp->shouldReceive('unlimit')->once();

        $job = new IpAddressAction($mockIp, 'unlimit');
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
