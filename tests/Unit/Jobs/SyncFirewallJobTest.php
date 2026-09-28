<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Enums\FirewallAction;
use App\Jobs\SyncFirewallJob;
use App\Models\IpAddress;
use App\Services\IpAddressActionService;
use App\Support\Queues;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SyncFirewallJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array<string, array{FirewallAction, string, bool, string, string}>
     */
    public static function handleProvider(): array
    {
        return [
            'enable internet' => [FirewallAction::Internet, 'internet_enabled', true, 'enableInternet', 'disableInternet'],
            'disable internet' => [FirewallAction::Internet, 'internet_enabled', false, 'disableInternet', 'enableInternet'],
            'enable rate limit' => [FirewallAction::RateLimit, 'rate_limit_enabled', true, 'enableRateLimit', 'disableRateLimit'],
            'disable rate limit' => [FirewallAction::RateLimit, 'rate_limit_enabled', false, 'disableRateLimit', 'enableRateLimit'],
        ];
    }

    /**
     * @return array<string, array{FirewallAction}>
     */
    public static function actionProvider(): array
    {
        return [
            'internet' => [FirewallAction::Internet],
            'rate limit' => [FirewallAction::RateLimit],
        ];
    }

    public function test_has_correct_retry_configuration(): void
    {
        $job = new SyncFirewallJob(IpAddress::factory()->make(), FirewallAction::Internet);

        $this->assertSame(3, $job->tries);
        $this->assertSame(30, $job->timeout);
        $this->assertSame([2, 10, 30], $job->backoff());
        $this->assertSame(Queues::ACCESS, $job->queue);
    }

    public function test_serialises_per_ip_with_overlap_middleware(): void
    {
        $job = new SyncFirewallJob(IpAddress::factory()->make(['address' => '10.0.0.50']), FirewallAction::Internet);

        $middleware = $job->middleware();
        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame(SyncFirewallJob::class.':10.0.0.50', $middleware[0]->key);
        $this->assertNotNull($middleware[0]->expiresAfter);
    }

    #[DataProvider('handleProvider')]
    public function test_handle_applies_current_state(FirewallAction $action, string $column, bool $enabled, string $expected, string $notExpected): void
    {
        $ip = IpAddress::factory()->create([$column => $enabled]);

        $this->mock(IpAddressActionService::class, function (MockInterface $mock) use ($expected, $notExpected): void {
            $mock->shouldReceive($expected)->once();
            $mock->shouldNotReceive($notExpected);
        });

        $this->app->call([new SyncFirewallJob($ip, $action), 'handle']);
    }

    public function test_delayed_enable_retry_applies_the_latest_disabled_state(): void
    {
        $ip = IpAddress::factory()->create(['internet_enabled' => true]);
        $staleJob = new SyncFirewallJob($ip, FirewallAction::Internet);

        $ip->update(['internet_enabled' => false]);

        $this->mock(IpAddressActionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('disableInternet')->once();
            $mock->shouldNotReceive('enableInternet');
        });

        $this->app->call([$staleJob, 'handle']);
    }

    #[DataProvider('actionProvider')]
    public function test_skips_when_ip_no_longer_exists(FirewallAction $action): void
    {
        $ip = IpAddress::factory()->create();
        $job = new SyncFirewallJob($ip, $action);
        $ip->delete();

        $this->mock(IpAddressActionService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('enableInternet', 'disableInternet', 'enableRateLimit', 'disableRateLimit');
        });

        $this->app->call([$job, 'handle']);
    }

    #[DataProvider('actionProvider')]
    public function test_failed_logs_error(FirewallAction $action): void
    {
        $ip = IpAddress::factory()->make(['address' => '10.0.0.50']);
        $job = new SyncFirewallJob($ip, $action);

        Log::shouldReceive('error')
            ->once()
            ->with('SyncFirewallJob failed', [
                'action' => $action->value,
                'ip' => '10.0.0.50',
                'error' => 'Connection timed out',
            ]);

        $job->failed(new RuntimeException('Connection timed out'));
    }
}
