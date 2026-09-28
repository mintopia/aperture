<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Enums\FirewallAction;
use App\Jobs\SyncFirewallJob;
use App\Models\IpAddress;
use App\Services\IpAddressActionService;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SyncFirewallJobTest extends TestCase
{
    /**
     * @return array<string, array{FirewallAction, bool, string, string}>
     */
    public static function handleProvider(): array
    {
        return [
            'enable internet' => [FirewallAction::Internet, true, 'enableInternet', 'disableInternet'],
            'disable internet' => [FirewallAction::Internet, false, 'disableInternet', 'enableInternet'],
            'enable rate limit' => [FirewallAction::RateLimit, true, 'enableRateLimit', 'disableRateLimit'],
            'disable rate limit' => [FirewallAction::RateLimit, false, 'disableRateLimit', 'enableRateLimit'],
        ];
    }

    public function test_has_correct_retry_configuration(): void
    {
        $job = new SyncFirewallJob(IpAddress::factory()->make(), FirewallAction::Internet, true);

        $this->assertSame(3, $job->tries);
        $this->assertSame(30, $job->timeout);
        $this->assertSame([2, 10, 30], $job->backoff());
    }

    #[DataProvider('handleProvider')]
    public function test_handle_calls_expected_service_method(FirewallAction $action, bool $enabled, string $expected, string $notExpected): void
    {
        $ip = IpAddress::factory()->make();

        $this->mock(IpAddressActionService::class, function (MockInterface $mock) use ($ip, $expected, $notExpected): void {
            $mock->shouldReceive($expected)->with($ip)->once();
            $mock->shouldNotReceive($notExpected);
        });

        $job = new SyncFirewallJob($ip, $action, $enabled);
        $this->app->call([$job, 'handle']);
    }

    #[DataProvider('handleProvider')]
    public function test_failed_logs_error(FirewallAction $action): void
    {
        $ip = IpAddress::factory()->make(['address' => '10.0.0.50']);
        $job = new SyncFirewallJob($ip, $action, true);

        Log::shouldReceive('error')
            ->once()
            ->with('SyncFirewallJob failed', [
                'action' => $action->value,
                'ip' => '10.0.0.50',
                'enabled' => true,
                'error' => 'Connection timed out',
            ]);

        $job->failed(new RuntimeException('Connection timed out'));
    }
}
