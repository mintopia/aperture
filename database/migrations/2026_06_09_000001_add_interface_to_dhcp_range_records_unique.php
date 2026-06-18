<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Drop the old unique index (if it still exists).
        // The old key (integration, type, subnet, range_from, range_to) collapses
        // all IPv6 ranges (which have empty subnet/range_from/range_to) into one row.
        if ($this->indexExists('dhcp_range_records', 'dhcp_range_records_unique')) {
            Schema::table('dhcp_range_records', function (Blueprint $table): void {
                $table->dropUnique('dhcp_range_records_unique');
            });
        }

        // Step 2: Add the new unique index including interface (if it doesn't exist).
        // No dedupe needed: the old, stricter unique index guarantees no rows can
        // violate the new, wider key.
        if (! $this->indexExists('dhcp_range_records', 'dhcp_range_records_interface_unique')) {
            Schema::table('dhcp_range_records', function (Blueprint $table): void {
                $table->unique(
                    ['integration', 'type', 'interface', 'subnet', 'range_from', 'range_to'],
                    'dhcp_range_records_interface_unique',
                );
            });
        }
    }

    public function down(): void
    {
        // Step 1: Drop the new unique index (if it exists)
        if ($this->indexExists('dhcp_range_records', 'dhcp_range_records_interface_unique')) {
            Schema::table('dhcp_range_records', function (Blueprint $table): void {
                $table->dropUnique('dhcp_range_records_interface_unique');
            });
        }

        // Step 2: Dedupe rows that would violate the old, narrower key.
        // Range data is ephemeral sync state; keep the lowest id per old key.
        $keepIds = DB::table('dhcp_range_records')
            ->selectRaw('MIN(id) as id')
            ->groupBy('integration', 'type', 'subnet', 'range_from', 'range_to')
            ->pluck('id');

        DB::table('dhcp_range_records')
            ->whereNotIn('id', $keepIds)
            ->delete();

        // Step 3: Restore the old unique index (if it doesn't exist)
        if (! $this->indexExists('dhcp_range_records', 'dhcp_range_records_unique')) {
            Schema::table('dhcp_range_records', function (Blueprint $table): void {
                $table->unique(
                    ['integration', 'type', 'subnet', 'range_from', 'range_to'],
                    'dhcp_range_records_unique',
                );
            });
        }
    }

    /**
     * Check whether an index exists on a table.
     * Uses Laravel's built-in index listing which works across drivers.
     */
    private function indexExists(string $table, string $index): bool
    {
        return in_array($index, Schema::getIndexListing($table), true);
    }
};
