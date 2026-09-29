<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\Capability;
use App\Models\CapabilityAssignment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RenameSeatSyncCapabilityMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return iterable<string, array{list<array{string, string}>, array<string, string>}>
     */
    public static function assignments(): iterable
    {
        yield 'legacy row is renamed' => [[['seat-sync', 'seatpicker']], ['seat-picker' => 'seatpicker']];
        yield 'current row wins over legacy' => [[['seat-sync', 'old'], ['seat-picker', 'seatpicker']], ['seat-picker' => 'seatpicker']];
        yield 'other capabilities untouched' => [[['dhcp', 'kea']], ['dhcp' => 'kea']];
    }

    /**
     * @param  list<array{string, string}>  $rows
     * @param  array<string, string>  $expected
     */
    #[DataProvider('assignments')]
    public function test_migration_normalises_seat_sync_assignments(array $rows, array $expected): void
    {
        foreach ($rows as [$capability, $integration]) {
            DB::table('capability_assignments')->insert(['capability' => $capability, 'integration' => $integration, 'created_at' => now(), 'updated_at' => now()]);
        }

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_09_29_000001_rename_seat_sync_capability_assignments.php');
        $migration->up();

        $this->assertSame($expected, DB::table('capability_assignments')->orderBy('capability')->pluck('integration', 'capability')->all());
        $this->assertContainsOnlyInstancesOf(Capability::class, CapabilityAssignment::all()->map(fn (CapabilityAssignment $a): Capability => $a->capability));
    }
}
