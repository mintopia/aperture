<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Interfaces\FirewallBackendInterface;
use App\Services\ValueObjects\ReconcileResult;
use Mockery\MockInterface;
use Tests\TestCase;

class ReconcileInternetCommandTest extends TestCase
{
    public function test_command_calls_reconcile_internet_without_dry_run(): void
    {
        $this->mock(FirewallBackendInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reconcileInternet')
                ->with(false)
                ->once()
                ->andReturn(new ReconcileResult(
                    added: ['10.0.0.1'],
                    removed: ['10.0.0.2'],
                    unchanged: ['10.0.0.3'],
                    errors: [],
                ));
        });

        $this->artisan('aperture:reconcile-internet')
            ->assertExitCode(0);
    }

    public function test_command_calls_reconcile_internet_with_dry_run(): void
    {
        $this->mock(FirewallBackendInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reconcileInternet')
                ->with(true)
                ->once()
                ->andReturn(new ReconcileResult(
                    added: ['10.0.0.1'],
                    removed: [],
                    unchanged: ['10.0.0.3'],
                    errors: [],
                ));
        });

        $this->artisan('aperture:reconcile-internet', ['--dry-run' => true])
            ->assertExitCode(0);
    }

    public function test_command_outputs_summary(): void
    {
        $this->mock(FirewallBackendInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reconcileInternet')
                ->with(false)
                ->once()
                ->andReturn(new ReconcileResult(
                    added: ['10.0.0.1'],
                    removed: ['10.0.0.2'],
                    unchanged: ['10.0.0.3'],
                    errors: [],
                ));
        });

        $this->artisan('aperture:reconcile-internet')
            ->expectsOutputToContain('Added: 1, Removed: 1, Unchanged: 1, Errors: 0')
            ->assertExitCode(0);
    }

    public function test_command_outputs_dry_run_notice(): void
    {
        $this->mock(FirewallBackendInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reconcileInternet')
                ->with(true)
                ->once()
                ->andReturn(new ReconcileResult(
                    added: [],
                    removed: [],
                    unchanged: [],
                    errors: [],
                ));
        });

        $this->artisan('aperture:reconcile-internet', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN] No changes applied.')
            ->assertExitCode(0);
    }

    public function test_command_outputs_errors(): void
    {
        $this->mock(FirewallBackendInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reconcileInternet')
                ->with(false)
                ->once()
                ->andReturn(new ReconcileResult(
                    added: [],
                    removed: [],
                    unchanged: [],
                    errors: ['Failed to remove 10.0.0.9'],
                ));
        });

        $this->artisan('aperture:reconcile-internet')
            ->expectsOutputToContain('Failed to remove 10.0.0.9')
            ->assertExitCode(0);
    }
}
