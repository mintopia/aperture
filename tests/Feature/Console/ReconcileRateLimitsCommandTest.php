<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Interfaces\RateLimitingInterface;
use App\Services\ValueObjects\ReconcileResult;
use Mockery\MockInterface;
use Tests\TestCase;

class ReconcileRateLimitsCommandTest extends TestCase
{
    public function test_command_calls_reconcile_rate_limits_without_dry_run(): void
    {
        $this->mock(RateLimitingInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reconcile')
                ->with(false)
                ->once()
                ->andReturn(new ReconcileResult(
                    added: ['10.0.0.1'],
                    removed: ['10.0.0.2'],
                    unchanged: ['10.0.0.3'],
                    errors: [],
                ));
        });

        $this->artisan('aperture:reconcile-rate-limits')
            ->assertExitCode(0);
    }

    public function test_command_calls_reconcile_rate_limits_with_dry_run(): void
    {
        $this->mock(RateLimitingInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reconcile')
                ->with(true)
                ->once()
                ->andReturn(new ReconcileResult(
                    added: ['10.0.0.1'],
                    removed: [],
                    unchanged: ['10.0.0.3'],
                    errors: [],
                ));
        });

        $this->artisan('aperture:reconcile-rate-limits', ['--dry-run' => true])
            ->assertExitCode(0);
    }

    public function test_command_outputs_summary(): void
    {
        $this->mock(RateLimitingInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reconcile')
                ->with(false)
                ->once()
                ->andReturn(new ReconcileResult(
                    added: ['10.0.0.1'],
                    removed: ['10.0.0.2'],
                    unchanged: ['10.0.0.3'],
                    errors: [],
                ));
        });

        $this->artisan('aperture:reconcile-rate-limits')
            ->expectsOutputToContain('Added: 1, Removed: 1, Unchanged: 1, Errors: 0')
            ->assertExitCode(0);
    }

    public function test_command_outputs_dry_run_notice(): void
    {
        $this->mock(RateLimitingInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reconcile')
                ->with(true)
                ->once()
                ->andReturn(new ReconcileResult(
                    added: [],
                    removed: [],
                    unchanged: [],
                    errors: [],
                ));
        });

        $this->artisan('aperture:reconcile-rate-limits', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN] No changes applied.')
            ->assertExitCode(0);
    }

    public function test_command_outputs_errors(): void
    {
        $this->mock(RateLimitingInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reconcile')
                ->with(false)
                ->once()
                ->andReturn(new ReconcileResult(
                    added: [],
                    removed: [],
                    unchanged: [],
                    errors: ['Failed to limit 10.0.0.9'],
                ));
        });

        $this->artisan('aperture:reconcile-rate-limits')
            ->expectsOutputToContain('Failed to limit 10.0.0.9')
            ->assertExitCode(0);
    }
}
