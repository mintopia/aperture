<?php

namespace Tests\Unit\Jobs;

use App\Jobs\IpAddressAction;
use App\Models\IpAddress;
use App\Services\IpAddressActionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class IpAddressActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_has_correct_retry_configuration(): void
    {
        $ip = IpAddress::factory()->create();
        $job = new IpAddressAction($ip, 'enableInternet');

        $this->assertSame(3, $job->tries);
        $this->assertSame(30, $job->timeout);
        $this->assertSame([2, 10, 30], $job->backoff());
    }

    public function test_failed_logs_error(): void
    {
        $ip = IpAddress::factory()->create();
        $job = new IpAddressAction($ip, 'enableInternet');

        Log::shouldReceive('error')
            ->once()
            ->with('IpAddressAction failed', [
                'ip' => $ip->address,
                'method' => 'enableInternet',
                'error' => 'Service unavailable',
            ]);

        $job->failed(new RuntimeException('Service unavailable'));
    }

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
