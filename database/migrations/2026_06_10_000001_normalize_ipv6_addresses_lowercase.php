<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Lowercase IPv6 values stored before IpAddress::normalize() was applied at
     * the write paths. On production MySQL/MariaDB the case-insensitive collation
     * meant lookups kept matching the old uppercase rows, so they were never
     * rewritten. Idempotent: already-lowercase rows are left untouched.
     */
    public function up(): void
    {
        // ip_addresses.address has no unique index, but lowering a row to a value
        // that already exists as a separate row (possible for SQLite-origin data,
        // where lookups are case-sensitive) would create an ambiguous duplicate.
        // Such rows are skipped: merging duplicates would require repointing the
        // foreign keys that reference them, which is out of scope here.
        $this->lowercaseIpv6Column('ip_addresses', 'address', ['address']);

        // dhcp_range_records.prefix is not part of any unique key, so it can be
        // lowered unconditionally.
        $this->lowercaseIpv6Column('dhcp_range_records', 'prefix', null);

        // dhcp_snooping_observations.ip is part of the dhcp_snooping_unique key;
        // rows whose lowered ip would collide are skipped (stale case-variant
        // duplicates are pruned by PortSyncService on the next sync).
        $this->lowercaseIpv6Column('dhcp_snooping_observations', 'ip', ['switch_config_id', 'vlan', 'ip', 'mac']);
    }

    public function down(): void
    {
        // Intentional no-op: the original mixed-case values are not recoverable.
    }

    /**
     * Lowercase IPv6 values (rows whose value contains ':') in the given column.
     *
     * @param  list<string>|null  $uniqueBy  Columns of a unique (or logically
     *                                       unique) key containing $column; when
     *                                       provided, rows whose lowered value
     *                                       would collide with an existing row
     *                                       are skipped instead of updated.
     */
    private function lowercaseIpv6Column(string $table, string $column, ?array $uniqueBy): void
    {
        $columns = array_values(array_unique(array_merge(['id', $column], $uniqueBy ?? [])));

        DB::table($table)
            ->select($columns)
            ->where($column, 'like', '%:%')
            ->orderBy('id')
            ->chunkById(500, function (Collection $rows) use ($table, $column, $uniqueBy): void {
                foreach ($rows as $row) {
                    $current = (string) $row->{$column};
                    $lowered = strtolower($current);

                    if ($lowered === $current) {
                        continue;
                    }

                    if ($uniqueBy !== null && $this->wouldCollide($table, $column, $lowered, $row, $uniqueBy)) {
                        continue;
                    }

                    DB::table($table)->where('id', $row->id)->update([$column => $lowered]);
                }
            });
    }

    /**
     * Determine whether another row already holds the lowered value on the
     * given unique key. Comparison semantics follow the connection (binary on
     * SQLite, collation-aware on MySQL/MariaDB), matching the constraint that
     * an update would actually violate.
     *
     * @param  list<string>  $uniqueBy
     */
    private function wouldCollide(string $table, string $column, string $lowered, object $row, array $uniqueBy): bool
    {
        $query = DB::table($table)->where('id', '!=', $row->id);

        foreach ($uniqueBy as $uniqueColumn) {
            $query->where($uniqueColumn, $uniqueColumn === $column ? $lowered : $row->{$uniqueColumn});
        }

        return $query->exists();
    }
};
