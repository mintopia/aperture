<?php

namespace Tests\Unit\Jobs;

use App\Jobs\IpAddressAction;
use App\Models\IpAddress;
use App\Services\IpAddressActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class IpAddressActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_calls_specified_method_on_service(): void
    {
        $ip = IpAddress::factory()->create();
        /** @var IpAddressActionService&MockInterface $service */
        $service = Mockery::mock(IpAddressActionService::class);
        $service->shouldReceive('enableInternet')->once()->with($ip);

        $job = new IpAddressAction($ip, 'enableInternet');
        $job->handle($service);
    }

    public function test_handle_calls_disable_internet_on_service(): void
    {
        $ip = IpAddress::factory()->create();
        /** @var IpAddressActionService&MockInterface $service */
        $service = Mockery::mock(IpAddressActionService::class);
        $service->shouldReceive('disableInternet')->once()->with($ip);

        $job = new IpAddressAction($ip, 'disableInternet');
        $job->handle($service);
    }

    public function test_handle_calls_enable_rate_limit_on_service(): void
    {
        $ip = IpAddress::factory()->create();
        /** @var IpAddressActionService&MockInterface $service */
        $service = Mockery::mock(IpAddressActionService::class);
        $service->shouldReceive('enableRateLimit')->once()->with($ip);

        $job = new IpAddressAction($ip, 'enableRateLimit');
        $job->handle($service);
    }

    public function test_handle_calls_disable_rate_limit_on_service(): void
    {
        $ip = IpAddress::factory()->create();
        /** @var IpAddressActionService&MockInterface $service */
        $service = Mockery::mock(IpAddressActionService::class);
        $service->shouldReceive('disableRateLimit')->once()->with($ip);

        $job = new IpAddressAction($ip, 'disableRateLimit');
        $job->handle($service);
    }

    public function test_handle_rejects_invalid_method_and_logs_error(): void
    {
        $ip = IpAddress::factory()->create();
        /** @var IpAddressActionService&MockInterface $service */
        $service = Mockery::mock(IpAddressActionService::class);
        $service->shouldReceive()->never();

        Log::shouldReceive('error')
            ->once()
            ->with('Invalid IpAddressAction method', ['method' => 'deleteAll']);

        $job = new IpAddressAction($ip, 'deleteAll');
        $job->handle($service);
    }
}
