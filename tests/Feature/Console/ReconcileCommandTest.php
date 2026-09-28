<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Interfaces\CaptivePortalInterface;
use App\Services\Interfaces\DnsFilteringInterface;
use App\Services\Interfaces\RateLimitingInterface;
use App\Services\ValueObjects\ReconcileResult;
use Illuminate\Support\Facades\Artisan;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReconcileCommandTest extends TestCase
{
    /**
     * @return array<string, array{string, class-string}>
     */
    public static function targets(): array
    {
        return [
            'internet' => ['internet', CaptivePortalInterface::class],
            'rate-limits' => ['rate-limits', RateLimitingInterface::class],
            'dns-filtering' => ['dns-filtering', DnsFilteringInterface::class],
        ];
    }

    /**
     * @param  class-string  $interface
     * @param  list<string>  $errors
     */
    private function mockReconcile(string $interface, bool $dryRun, array $errors = []): void
    {
        $this->mock($interface, function (MockInterface $mock) use ($dryRun, $errors): void {
            $mock->shouldReceive('reconcile')
                ->with($dryRun)
                ->once()
                ->andReturn(new ReconcileResult(
                    added: ['10.0.0.1'],
                    removed: ['10.0.0.2'],
                    unchanged: ['10.0.0.3'],
                    errors: $errors,
                ));
        });
    }

    /**
     * @param  class-string  $interface
     */
    #[DataProvider('targets')]
    public function test_reconciles_target_and_outputs_summary(string $target, string $interface): void
    {
        $this->mockReconcile($interface, false);

        $this->artisan('aperture:reconcile', ['target' => $target])
            ->expectsOutputToContain('Added: 1, Removed: 1, Unchanged: 1, Errors: 0')
            ->assertExitCode(0);
    }

    /**
     * @param  class-string  $interface
     */
    #[DataProvider('targets')]
    public function test_dry_run_passes_flag_and_outputs_notice(string $target, string $interface): void
    {
        $this->mockReconcile($interface, true);

        $this->artisan('aperture:reconcile', ['target' => $target, '--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN] No changes applied.')
            ->assertExitCode(0);
    }

    /**
     * @param  class-string  $interface
     */
    #[DataProvider('targets')]
    public function test_outputs_errors(string $target, string $interface): void
    {
        $this->mockReconcile($interface, false, ['Failed to process 10.0.0.9']);

        $this->artisan('aperture:reconcile', ['target' => $target])
            ->expectsOutputToContain('Failed to process 10.0.0.9')
            ->assertExitCode(0);
    }

    public function test_unknown_target_fails(): void
    {
        $this->artisan('aperture:reconcile', ['target' => 'bogus'])
            ->expectsOutputToContain('Unknown target "bogus"')
            ->assertExitCode(1);
    }

    public function test_old_command_names_are_removed(): void
    {
        $this->assertArrayNotHasKey('aperture:reconcile-internet', Artisan::all());
        $this->assertArrayNotHasKey('aperture:reconcile-rate-limits', Artisan::all());
        $this->assertArrayNotHasKey('aperture:reconcile-dns-filtering', Artisan::all());
    }
}
